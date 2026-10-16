"""Regenerate only the already verified City Hostelworld global filter."""
from hostelworld_filters import NAME, expected, rows, verify_filter
from cpanel_api import require

def refresh_city(api):
    require(api.account == 'city', 'unexpected_refresh_account')
    params = expected('city', 3)
    before = rows(api.call('Email', 'list_filters'))
    # The observed City global configuration contains only our rule. Refuse to
    # regenerate if that has changed, rather than touching unrelated routing.
    require(len(before) == 1, 'unexpected_global_filter_configuration')
    verify_filter(before[0], params)
    api.call('Email', 'store_filter', oldfiltername=NAME, **params)
    after = rows(api.call('Email', 'list_filters'))
    require(len(after) == 1, 'unexpected_global_filter_result')
    verify_filter(after[0], params)
