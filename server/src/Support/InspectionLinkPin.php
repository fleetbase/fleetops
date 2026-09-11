<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Mail\InspectionLinkPinMail;
use Fleetbase\FleetOps\Models\InspectionLink;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Services\SmsService;
use Fleetbase\Support\Utils;
use Illuminate\Support\Facades\Mail;

/**
 * Sends an inspection link and its PIN to whoever the link is for.
 *
 * The message carries both, so the recipient can open the inspection straight
 * from it. The PIN still stops anyone who has only the link, such as a link
 * copied or forwarded on its own, and it locks the link after a few wrong
 * guesses.
 */
class InspectionLinkPin
{
    public const VIA = ['email', 'sms'];

    /** Who a link's PIN goes to: the assignee, or failing that the driver's account. */
    public static function recipientFor(InspectionLink $link): ?User
    {
        return $link->assignee ?? $link->driver?->user;
    }

    /** Why a PIN cannot go to this person this way, or null when it can. */
    public static function unavailableReason(?User $recipient, string $via): ?string
    {
        if (!$recipient) {
            return 'Assign the link to someone, or pick a driver, to send the PIN to.';
        }

        if ($via === 'sms' && !filled($recipient->phone)) {
            return ($recipient->name ?: 'This person') . ' has no phone number to text the PIN to.';
        }

        if ($via === 'email' && !filled($recipient->email)) {
            return ($recipient->name ?: 'This person') . ' has no email address to send the PIN to.';
        }

        return null;
    }

    /**
     * Send the link's PIN. Never throws: a failed delivery is reported in the
     * result, so minting a link does not fail because a mailer is misconfigured.
     *
     * @return array{sent: bool, via: string, to: ?string, error: ?string}
     */
    public static function send(InspectionLink $link, string $via): array
    {
        $recipient = static::recipientFor($link);
        $reason    = static::unavailableReason($recipient, $via);

        if (!$link->pin) {
            $reason = 'This link has no PIN to send.';
        }

        if ($reason) {
            return ['sent' => false, 'via' => $via, 'to' => null, 'error' => $reason];
        }

        try {
            if ($via === 'sms') {
                $result = (new SmsService())->send($recipient->phone, static::smsText($link, $link->pin, static::urlFor($link)), static::smsOptions($link));

                if (is_array($result) && array_key_exists('success', $result) && !$result['success']) {
                    return ['sent' => false, 'via' => $via, 'to' => null, 'error' => 'The PIN could not be texted: ' . ($result['error'] ?? $result['message'] ?? 'the SMS provider refused it.')];
                }

                $to = static::maskPhone($recipient->phone);
            } else {
                Mail::to($recipient)->send(new InspectionLinkPinMail($link, $link->pin, $recipient, static::urlFor($link)));
                $to = static::maskEmail($recipient->email);
            }
        } catch (\Throwable $e) {
            report($e);

            return ['sent' => false, 'via' => $via, 'to' => null, 'error' => 'The PIN could not be sent: ' . $e->getMessage()];
        }

        $link->forceFill(['pin_sent_via' => $via, 'pin_sent_at' => now()])->save();

        return ['sent' => true, 'via' => $via, 'to' => $to, 'error' => null];
    }

    /**
     * The link as the recipient opens it, on the console's own host. Null for a
     * link minted before tokens were kept, whose URL cannot be rebuilt.
     */
    public static function urlFor(InspectionLink $link): ?string
    {
        $path = $link->path;

        return $path ? Utils::consoleUrl($path) : null;
    }

    /** Naming the organisation so it is recognised, and short enough to read at a glance. */
    public static function smsText(InspectionLink $link, string $pin, ?string $url = null): string
    {
        $company = Company::select(['uuid', 'name'])->find($link->company_uuid)?->name ?? config('app.name');
        $form    = $link->form?->name ?? 'inspection';

        if ($url) {
            return "{$company}: complete the {$form} inspection at {$url} using PIN {$pin}. Do not share this PIN.";
        }

        return "{$company}: your PIN for the {$form} inspection is {$pin}. Do not share this PIN.";
    }

    /**
     * The organisation's alphanumeric sender ID, when it has one, the same way
     * the platform's own verification texts use it: as a Twilio-only option,
     * so other providers are not handed a sender they would reject.
     */
    protected static function smsOptions(InspectionLink $link): array
    {
        $company = Company::select(['uuid', 'options'])->find($link->company_uuid);

        if (!$company) {
            return [];
        }

        $enabled  = Utils::castBoolean($company->getOption('alpha_numeric_sender_id_enabled'));
        $senderId = $company->getOption('alpha_numeric_sender_id');

        return $enabled && $senderId ? ['twilioParams' => ['from' => $senderId]] : [];
    }

    /** "r•••@fleetbase.io": enough to recognise, not enough to copy. */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1) . '•••@' . $domain;
    }

    /** "•••1969": the last four digits. */
    public static function maskPhone(string $phone): string
    {
        return '•••' . substr(preg_replace('/\D/', '', $phone), -4);
    }
}
