<?php
declare(strict_types=1);

final class BookingLoginStatus
{
    /**
     * Convert the private Booking diagnostic into a small, safe summary for the Hub.
     * Only explicit signals are shown; missing data is never presented as an SMS or CAPTCHA.
     *
     * @return array{phase:string,title:string,detail:string,next:string}
     */
    public static function summarize(array $diagnostic): array
    {
        if (isset($diagnostic['diagnostic']) && is_array($diagnostic['diagnostic'])) {
            $diagnostic = $diagnostic['diagnostic'];
        }

        $authenticated = ($diagnostic['authenticated_session'] ?? null) === true;
        $attempted = ($diagnostic['login_attempted'] ?? null) === true;
        $failure = is_string($diagnostic['failure_code'] ?? null) ? $diagnostic['failure_code'] : '';
        $captcha = is_string($diagnostic['captcha_status'] ?? null) ? $diagnostic['captcha_status'] : '';

        if ($authenticated) {
            return [
                'phase' => 'authenticated',
                'title' => 'booking_login_authenticated',
                'detail' => $attempted ? 'booking_login_authenticated_auto' : 'booking_login_authenticated_session',
                'next' => 'booking_login_no_action',
            ];
        }

        if (($diagnostic['sms_prompted'] ?? null) === true) {
            if ($failure === 'auth_invalid') {
                return ['phase' => 'sms_rejected', 'title' => 'booking_login_sms_rejected',
                    'detail' => 'booking_login_sms_rejected_detail', 'next' => 'booking_login_sms_retry'];
            }
            if ($failure === 'auth_timeout') {
                return ['phase' => 'sms_timeout', 'title' => 'booking_login_sms_timeout',
                    'detail' => 'booking_login_sms_timeout_detail', 'next' => 'booking_login_sms_retry'];
            }
            if (($diagnostic['sms_submitted'] ?? null) === true) {
                return ['phase' => 'sms_submitted', 'title' => 'booking_login_sms_submitted',
                    'detail' => 'booking_login_sms_submitted_detail', 'next' => 'booking_login_sms_result'];
            }
            return ['phase' => 'sms_waiting', 'title' => 'booking_login_sms_waiting',
                'detail' => 'booking_login_sms_waiting_detail', 'next' => 'booking_login_sms_check'];
        }

        if ($failure === 'human_verification' || in_array($captcha, [
            'unconfigured', 'unsupported', 'provider_error', 'browser_error', 'browser_timeout',
            'browser_context_lost', 'browser_cookie_conflict', 'browser_cookie_unavailable',
            'browser_callback_error', 'browser_page_closed', 'browser_navigation_aborted',
            'timeout', 'stale', 'not_accepted',
        ], true)) {
            return ['phase' => 'captcha', 'title' => 'booking_login_captcha',
                'detail' => $failure === 'human_verification' || $captcha === 'not_accepted'
                    ? 'booking_login_captcha_detail' : 'booking_login_captcha_setup_detail',
                'next' => 'booking_login_captcha_next'];
        }

        if ($failure === 'auth_invalid') {
            return ['phase' => 'credentials_rejected', 'title' => 'booking_login_rejected',
                'detail' => 'booking_login_rejected_detail', 'next' => 'booking_login_rejected_next'];
        }
        if ($failure === 'auth_timeout') {
            return ['phase' => 'timeout', 'title' => 'booking_login_timeout',
                'detail' => 'booking_login_timeout_detail', 'next' => 'booking_login_retry_next'];
        }

        $stage = is_string($diagnostic['failure_stage'] ?? null) ? $diagnostic['failure_stage'] : '';
        if (in_array($stage, ['identifier', 'password'], true)) {
            return ['phase' => 'credentials', 'title' => 'booking_login_credentials',
                'detail' => $stage === 'identifier' ? 'booking_login_identifier_detail' : 'booking_login_password_detail',
                'next' => 'booking_login_credentials_next'];
        }
        if ($failure === 'needs_auth' || $failure === 'portal_changed' || $failure !== '') {
            return ['phase' => 'blocked', 'title' => 'booking_login_blocked',
                'detail' => 'booking_login_blocked_detail', 'next' => 'booking_login_blocked_next'];
        }

        return ['phase' => 'unknown', 'title' => 'booking_login_unknown',
            'detail' => 'booking_login_unknown_detail', 'next' => 'booking_login_retry_next'];
    }
}
