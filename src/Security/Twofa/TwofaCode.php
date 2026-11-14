<?php

declare(strict_types=1);

namespace App\Security\Twofa;

use Symfony\Component\HttpFoundation\Request;

/** The code a user typed in a 2FA form (params[:twofa_code]). */
final class TwofaCode
{
    public static function fromRequest(Request $request): string
    {
        $code = $request->request->get('twofa_code', $request->query->get('twofa_code', ''));

        return \is_scalar($code) ? (string) $code : '';
    }
}
