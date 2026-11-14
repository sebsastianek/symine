<?php

declare(strict_types=1);

namespace App\Service\Scm\Sys;

use Symfony\Component\HttpFoundation\Request;

/** Reads the parameters of a request of the repository management web service, as Rails merges them (params). */
final class SysApiRequest
{
    /** The parameter of the query string, else of the form body, else of a JSON body; null when it is not given */
    public static function param(Request $request, string $name): mixed
    {
        if ($request->query->has($name)) {
            return \is_array($request->query->all()[$name] ?? null) ? $request->query->all($name) : $request->query->get($name);
        }
        if ($request->request->has($name)) {
            return \is_array($request->request->all()[$name] ?? null) ? $request->request->all($name) : $request->request->get($name);
        }
        $ct = (string) $request->headers->get('Content-Type');
        if (str_contains($ct, 'json')) {
            $data = json_decode((string) $request->getContent(), true);

            return \is_array($data) ? ($data[$name] ?? null) : null;
        }

        return null;
    }
}
