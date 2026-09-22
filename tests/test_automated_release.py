"""Release decisions use fake services; no hosting access or mutations."""
import copy
import contextlib
import io
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).parents[1] / 'deploy'))
import automated_release as release

OLD, NEW = '1' * 40, '2' * 40


class Runner:
    task_state = staticmethod(release.Deployment.task_state)
    task_id = staticmethod(release.Deployment.task_id)

    def __init__(self, current=OLD, deployed=OLD, task='succeeded'):
        self.current, self.deployed, self.task = current, deployed, task
        self.writes = []

    def doctor(self):
        return {'transport': 'whm', 'cpanel_user': 'welcome'}

    def repository(self):
        return {'head': self.current, 'last_deployment': {'repository_state':
            {'branch': release.BRANCH, 'identifier': self.deployed}}}

    def inspect(self, row, expected=None):
        if expected is not None:
            release.require(row['head'] == expected, 'unexpected_commit')
        return row['head']

    def tasks(self):
        return [{'deploy_id': '17', 'timestamps': {self.task: '100'},
                 'repository_state': {'branch': release.BRANCH, 'identifier': self.deployed}}]

    def update(self, current, target):
        assert current == self.current
        self.writes.append('update')
        self.current = target

    def deploy(self, target):
        assert target == self.current
        self.writes.append('deploy')
        self.deployed = target
        return {'deploy_id': '18'}


class ReleaseTests(unittest.TestCase):
    def run_release(self, runner, github=None, **changes):
        return release.release(runner, github or Mock(), NEW,
                               check_health=lambda: {'https_login': 'passed'},
                               check_backup=lambda: None, **changes)

    def test_valid_release_updates_then_deploys(self):
        runner, github = Runner(), Mock()
        result = self.run_release(runner, github)
        self.assertEqual(runner.writes, ['update', 'deploy'])
        self.assertEqual(result['backup_gate'], 'deployment_cli_passed')
        github.require_fast_forward.assert_called_once_with(OLD, NEW)
        self.assertEqual(github.require_head.call_count, 4)

    def test_current_and_deployed_is_readonly(self):
        runner = Runner(NEW, NEW)
        self.assertEqual(self.run_release(runner)['state'], 'already_deployed')
        self.assertEqual(runner.writes, [])

    def test_current_but_not_deployed_skips_pull(self):
        runner = Runner(NEW, OLD)
        self.run_release(runner)
        self.assertEqual(runner.writes, ['deploy'])

    def test_stale_commit_never_writes(self):
        runner, github = Runner(), Mock()
        github.require_head.side_effect = release.DeploymentError('stale_release_remote_head_changed')
        with self.assertRaises(release.DeploymentError): self.run_release(runner, github)
        self.assertEqual(runner.writes, [])

    def test_failed_or_unfinished_previous_deployment_stops(self):
        for state in ('failed', 'canceled', 'active', 'queued', 'other'):
            runner = Runner(task=state)
            with self.subTest(state=state), self.assertRaises(release.DeploymentError):
                self.run_release(runner)
            self.assertEqual(runner.writes, [])

    def test_update_failure_prevents_deploy(self):
        runner = Runner()
        runner.update = Mock(side_effect=release.DeploymentError('request_failed_no_automatic_retry'))
        with self.assertRaises(release.DeploymentError): self.run_release(runner)
        self.assertEqual(runner.writes, [])

    def test_remote_advance_after_update_prevents_deploy(self):
        runner, github = Runner(), Mock()
        github.require_head.side_effect = [None, None, release.DeploymentError('stale')]
        with self.assertRaises(release.DeploymentError): self.run_release(runner, github)
        self.assertEqual(runner.writes, ['update'])

    def test_missing_backup_gate_stops_before_network(self):
        runner, github = Runner(), Mock()
        with tempfile.TemporaryDirectory() as root:
            Path(root, '.cpanel.yml').write_text('deployment:\n  tasks:\n    - echo no-backup\n')
            with self.assertRaises(release.DeploymentError):
                release.release(runner, github, NEW, check_backup=lambda: release.require_backup_gate(root))
        github.require_head.assert_not_called()
        self.assertEqual(runner.writes, [])

    def test_actual_backup_contract_present(self):
        release.require_backup_gate()

    def test_login_probe_rejects_error_page(self):
        http = Mock()
        http.get.return_value = (b'<html>Maintenance</html>', 'text/html')
        with self.assertRaises(release.DeploymentError): release.health_check(http)
        http.get.return_value = (b'<form method="post"><input name="username"><input name="password" type="password"><input name="csrf_token" type="hidden"></form>', 'text/html')
        self.assertEqual(release.health_check(http)['https_login'], 'passed')

    def test_github_fastforward_rejects_divergence(self):
        github = release.GitHub('fake-test-token')
        github.read = Mock(return_value={'merge_base_commit': {'sha': OLD},
            'status': 'diverged', 'behind_by': 1, 'ahead_by': 2})
        with self.assertRaises(release.DeploymentError): github.require_fast_forward(OLD, NEW)

    def test_diagnostic_never_releases_or_prints_private_details(self):
        runner = Mock()
        runner.doctor.return_value = {'private_metadata': 'should-not-be-printed'}
        output = io.StringIO()
        with patch.dict(os.environ, {'WHM_API_TOKEN': 'test-whm-secret'}), \
             patch.object(release, 'Deployment', return_value=runner), \
             patch.object(release, 'GitHub') as github, contextlib.redirect_stdout(output):
            self.assertEqual(release.main(['--diagnostic']), 0)
        github.assert_not_called()
        runner.deploy.assert_not_called()
        runner.update.assert_not_called()
        runner.doctor.assert_called_once_with()
        self.assertNotIn('should-not-be-printed', output.getvalue())
        self.assertNotIn('test-whm-secret', output.getvalue())


if __name__ == '__main__': unittest.main()
