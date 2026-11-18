<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Spider;

use HElturkey\WebTrawler\Policy\UrlNormalizer;
use GuzzleHttp\Psr7\Uri;

/** @internal Builds deterministic request identities without conflating distinct request inputs. */
final class RequestFingerprint
{
    public static function for(SpiderRequest $request): string
    {
        try {
            $url = (string) (new Uri(UrlNormalizer::normalize($request->url)))->withFragment('');
        } catch (\Throwable) {
            // Keep malformed requests distinct by their raw URL; SpiderRunner will report the validation error.
            $url = 'invalid:' . trim($request->url);
        }
        $payload = [];
        $optionValues = $request->options->extra ?? [];
        foreach (['form_params', 'json', 'query', 'body'] as $option) {
            if (array_key_exists($option, $request->extra)) {
                $payload[$option] = $request->extra[$option];
            } elseif (array_key_exists($option, $optionValues)) {
                $payload[$option] = $optionValues[$option];
            }
        }

        // Per-request options can change the representation returned by the same URL (for example,
        // Accept-Language, cookies, proxy region or redirect policy). Include them rather than merging
        // requests that merely happen to share a URL and method.
        $requestContext = [
            'extra' => $request->extra,
            'options' => $request->options === null ? null : [
                'headers' => $request->options->headers,
                'timeout' => $request->options->timeout,
                'connect_timeout' => $request->options->connectTimeout,
                'proxy' => $request->options->proxy,
                'verify_tls' => $request->options->verifyTls,
                'allow_redirects' => $request->options->allowRedirects,
                'max_redirects' => $request->options->maxRedirects,
                'extra' => $request->options->extra,
                'max_body_bytes' => $request->options->maxBodyBytes,
                'block_private_networks' => $request->options->blockPrivateNetworks,
                'encoding' => $request->options->encoding,
                'allowed_hosts' => $request->options->allowedHosts,
            ],
        ];

        $encodedPayload = self::encodeValue([$payload, $requestContext]);
        // Opaque bodies/objects are intentionally not deduplicated. A false negative costs a request;
        // treating two unknown payloads as equal could silently drop a meaningful request.
        if ($encodedPayload === null) {
            $encodedPayload = 'opaque:' . bin2hex(random_bytes(16));
        }

        // Appended only when set, so requests without a session keep their identity.
        $session = $request->session === null ? '' : "\0session:" . $request->session;

        return hash('sha256', strtoupper(trim($request->method)) . "\0" . $url . "\0" . $encodedPayload . $session);
    }

    private static function encodeValue(mixed $value): ?string
    {
        if ($value === null) {
            return 'n;';
        }
        if (is_bool($value)) {
            return $value ? 'b1;' : 'b0;';
        }
        if (is_int($value)) {
            return 'i' . $value . ';';
        }
        if (is_float($value)) {
            return is_finite($value) ? 'f' . bin2hex(pack('E', $value)) . ';' : null;
        }
        if (is_string($value)) {
            return 's' . strlen($value) . ':' . $value;
        }
        if (!is_array($value)) {
            return null;
        }

        $isList = array_is_list($value);
        /** @var list<array{string, string}> $entries */
        $entries = [];
        foreach ($value as $key => $entry) {
            $encodedValue = self::encodeValue($entry);
            if ($encodedValue === null) {
                return null;
            }
            $encodedKey = $isList ? '' : self::encodeValue($key);
            if ($encodedKey === null) {
                return null;
            }
            $entries[] = [$encodedKey, $encodedValue];
        }
        $encoded = $isList ? 'l' : 'a';
        foreach ($entries as [$key, $entry]) {
            $encoded .= $key . $entry;
        }

        return $encoded . ';';
    }
}
