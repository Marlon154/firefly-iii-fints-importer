<?php
namespace App\StepFunction;

use App\ConfigurationFactory;
use App\FinTsFactory;
use App\Logger;
use App\Step;
use App\TanHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

function Login()
{
    global $request, $session, $twig, $fin_ts, $automate_without_js;

    if ($request->request->has('bank_2fa_device')) {
        $session->set('bank_2fa_device', $request->request->get('bank_2fa_device'));
    }
    $current_step = new Step($request->request->get("step", Step::STEP0_SETUP));

    $make_login_handler = function () use ($current_step) {
        global $fin_ts, $session, $twig, $request;
        return new TanHandler(
            function () {
                global $fin_ts;
                // fresh start, forget any dialog that may have been persisted
                $fin_ts->forgetDialog();
                return $fin_ts->login();
            },
            'login-action',
            $session,
            $twig,
            $fin_ts,
            $current_step,
            $request
        );
    };

    try {
        $fin_ts        = FinTsFactory::create_from_session($session);
        $login_handler = $make_login_handler();
    } catch (\Exception $e) {
        // A stored persistence token may have expired or become invalid. If we have one, drop it and
        // retry with a fresh login (which will trigger a TAN challenge).
        if (!$session->has('fints_persistence')) {
            throw $e;
        }
        Logger::warn("Login failed with stored persistence token (possibly expired/invalid), retrying without it: " . $e->getMessage());
        $session->remove('fints_persistence');
        // Only touch the config file if the user opted into automatic persistence management.
        if ($session->has('config_file') && $session->get('auto_save_persistence')) {
            ConfigurationFactory::update_persistence_token($session->get('config_file'), null);
        }
        $fin_ts        = FinTsFactory::create_from_session($session);
        $login_handler = $make_login_handler();
    }

    if ($login_handler->needs_tan()) {
        $login_handler->pose_and_render_tan_challenge();
    } else {
        // Detect supported statement formats from BPD (now safely cached after login)
        $bpd = $fin_ts->getBpd();
        $supports_camt = $bpd->getLatestSupportedParameters('HICAZS') !== null;
        $supports_mt940 = $bpd->getLatestSupportedParameters('HIKAZS') !== null;

        if ($supports_camt) {
            $session->set('statement_format', 'camt');
            Logger::info("Bank supports CAMT XML format (HICAZS)");
        } elseif ($supports_mt940) {
            $session->set('statement_format', 'mt940');
            Logger::info("Bank supports MT940 format (HIKAZS)");
        } else {
            Logger::warn("Bank supports neither CAMT nor MT940 - will attempt CAMT first");
            $session->set('statement_format', 'camt'); // Default, let exception handling deal with it
        }

        if ($session->get('force_mt940')) {
            Logger::info("Forcing MT940 format as per configuration");
            $session->set('statement_format', 'mt940');
        }

        if ($automate_without_js)
        {
            $session->set('persistedFints', $fin_ts->persist());
            ConfigurationFactory::save_persistence($session);
            return Step::STEP3_CHOOSE_ACCOUNT;
        }
        echo $twig->render(
            'skip-form.twig',
            array(
                'next_step' => Step::STEP3_CHOOSE_ACCOUNT,
                'message' => "The connection to your bank was tested sucessfully."
            )
        );
    }
    $session->set('persistedFints', $fin_ts->persist());
    ConfigurationFactory::save_persistence($session);
    return Step::DONE;
}