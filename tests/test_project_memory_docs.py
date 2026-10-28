import importlib.util
import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "project_memory_guard", ROOT / "tools" / "project_memory_guard.py"
)
guard = importlib.util.module_from_spec(SPEC)
assert SPEC.loader
SPEC.loader.exec_module(guard)


class ProjectMemoryDocsTests(unittest.TestCase):
    def setUp(self):
        self.config = guard.load_config(ROOT / "docs" / "project-memory.json")

    def test_required_memory_files_exist(self):
        required = [
            "docs/PROJECT_MEMORY.md",
            "docs/PROJECT_STATE.md",
            "docs/flows/README.md",
            "docs/flows/cloudbeds-zkaccess.md",
            "docs/decisions/README.md",
            "docs/project-memory.json",
        ]
        for relative in required:
            self.assertTrue((ROOT / relative).is_file(), relative)

    def test_flow_index_links_resolve_and_flows_have_mermaid(self):
        index = (ROOT / "docs/flows/README.md").read_text(encoding="utf-8")
        flows = sorted((ROOT / "docs/flows").glob("*.md"))
        for flow in flows:
            if flow.name == "README.md":
                continue
            self.assertIn(f"]({flow.name})", index)
            self.assertIn("\`\`\`mermaid", flow.read_text(encoding="utf-8"))

    def test_adr_index_is_complete_and_numbers_are_unique(self):
        index = (ROOT / "docs/decisions/README.md").read_text(encoding="utf-8")
        adrs = sorted((ROOT / "docs/decisions").glob("ADR-*.md"))
        numbers = []
        for adr in adrs:
            self.assertIn(f"]({adr.name})", index)
            match = re.match(r"ADR-(\d{3})-", adr.name)
            self.assertIsNotNone(match, adr.name)
            numbers.append(match.group(1))
        self.assertEqual(len(numbers), len(set(numbers)))

    def test_state_uses_explicit_lifecycle_vocabulary(self):
        state = (ROOT / "docs/PROJECT_STATE.md").read_text(encoding="utf-8")
        for word in ("Prepared", "Integrated", "Deployed", "Verified"):
            self.assertIn(word, state)

    def test_test_only_or_document_only_changes_do_not_require_state(self):
        for files in (
            ["tests/example.php"],
            ["assets/app.css"],
            ["admin/assets/example.js"],
            ["docs/PROJECT_MEMORY.md"],
        ):
            self.assertEqual([], guard.evaluate(files, self.config))

    def test_generic_runtime_change_requires_project_state(self):
        errors = guard.evaluate(["src/Invoices/Foo.php"], self.config)
        self.assertTrue(any("PROJECT_STATE.md" in error for error in errors))
        self.assertEqual(
            [],
            guard.evaluate(
                ["src/Invoices/Foo.php", "docs/PROJECT_STATE.md"], self.config
            ),
        )

    def test_zkaccess_change_requires_state_and_flow(self):
        errors = guard.evaluate(["src/ZKAccess/Worker.php"], self.config)
        self.assertTrue(any("PROJECT_STATE.md" in error for error in errors))
        self.assertTrue(any("cloudbeds-zkaccess.md" in error for error in errors))
        self.assertEqual(
            [],
            guard.evaluate(
                [
                    "src/ZKAccess/Worker.php",
                    "docs/PROJECT_STATE.md",
                    "docs/flows/cloudbeds-zkaccess.md",
                ],
                self.config,
            ),
        )

    def test_deployment_change_requires_state(self):
        errors = guard.evaluate(["deploy/release.sh"], self.config)
        self.assertTrue(any("PROJECT_STATE.md" in error for error in errors))
        self.assertEqual(
            [],
            guard.evaluate(
                ["deploy/release.sh", "docs/PROJECT_STATE.md"], self.config
            ),
        )

    def test_config_is_valid_json_and_has_unique_domains(self):
        raw = json.loads((ROOT / "docs/project-memory.json").read_text(encoding="utf-8"))
        names = [domain["name"] for domain in raw["domains"]]
        self.assertEqual(len(names), len(set(names)))


if __name__ == "__main__":
    unittest.main()
