<?php

declare(strict_types=1);

namespace Bref\Event\Http;

use Bref\Context\Context;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Riverline\MultiPartParser\Part;
use RuntimeException;

/**
 * Bridges PSR-7 requests and responses with API Gateway or ALB event/response formats.
 *
 * @internal
 */
final class Psr7Bridge
{
    /**
     * Create a PSR-7 server request from an AWS Lambda HTTP event.
     */
    public static function convertRequest(HttpRequestEvent $event, Context $context): ServerRequestInterface
    {
        $headers = $event->getHeaders();

        [$files, $parsedBody] = self::parseBodyAndUploadedFiles($event);
        [$user, $password] = self::parseBasicAuthorization($headers);

        $server = array_filter([
            'CONTENT_LENGTH' => $headers['content-length'][0] ?? null,
            'CONTENT_TYPE' => $event->getContentType(),
            'DOCUMENT_ROOT' => getcwd(),
            'QUERY_STRING' => $event->getQueryString(),
            'REQUEST_METHOD' => $event->getMethod(),
            'SERVER_NAME' => $event->getServerName(),
            'SERVER_PORT' => $event->getServerPort(),
            'SERVER_PROTOCOL' => $event->getProtocol(),
            'PATH_INFO' => $event->getPath(),
            'HTTP_HOST' => $headers['host'] ?? null,
            'REMOTE_PORT' => $event->getRemotePort(),
            'REQUEST_TIME' => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
            'REQUEST_URI' => $event->getUri(),
            'PHP_AUTH_USER' => $user,
            'PHP_AUTH_PW' => $password,
        ], fn ($value) => ! is_null($value));

        foreach ($headers as $name => $values) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $values[0];
        }

        /**
         * Nyholm/psr7 does not rewind body streams, we do it manually
         * so that users can fetch the content of the body directly.
         */
        $bodyStream = Stream::create($event->getBody());
        $bodyStream->rewind();

        $request = new ServerRequest(
            $event->getMethod(),
            $event->getUri(),
            $event->getHeaders(),
            $bodyStream,
            $event->getProtocolVersion(),
            $server
        );

        foreach ($event->getPathParameters() as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }

        return $request->withUploadedFiles($files)
            ->withCookieParams($event->getCookies())
            ->withQueryParams($event->getQueryParameters())
            ->withParsedBody($parsedBody)
            ->withAttribute('lambda-event', $event)
            ->withAttribute('lambda-context', $context);
    }

    /**
     * Create a ALB/API Gateway response from a PSR-7 response.
     */
    public static function convertResponse(ResponseInterface $response): HttpResponse
    {
        $response->getBody()->rewind();
        $body = $response->getBody()->getContents();

        return new HttpResponse($body, $response->getHeaders(), $response->getStatusCode());
    }

    private static function parseBodyAndUploadedFiles(HttpRequestEvent $event): array
    {
        $bodyString = $event->getBody();
        $files = [];
        $parsedBody = null;
        $contentType = $event->getContentType();
        if ($contentType !== null && $event->getMethod() === 'POST') {
            if (substr($contentType, 0, 33) === 'application/x-www-form-urlencoded') {
                parse_str($bodyString, $parsedBody);
            } else {
                $document = new Part("Content-type: $contentType\r\n\r\n" . $bodyString);
                if ($document->isMultiPart()) {
                    $parsedBody = [];
                    foreach ($document->getParts() as $part) {
                        if ($part->isFile()) {
                            $tmpPath = tempnam(sys_get_temp_dir(), 'bref_upload_');
                            if ($tmpPath === false) {
                                throw new RuntimeException('Unable to create a temporary directory');
                            }
                            file_put_contents($tmpPath, $part->getBody());
                            $file = new UploadedFile($tmpPath, filesize($tmpPath), UPLOAD_ERR_OK, $part->getFileName(), $part->getMimeType());

                            self::parseKeyAndInsertValueInArray($files, $part->getName(), $file);
                        } else {
                            self::parseKeyAndInsertValueInArray($parsedBody, $part->getName(), $part->getBody());
                        }
                    }
                }
            }
        }
        return [$files, $parsedBody];
    }

    /**
     * Parse a string key like "files[id_cards][jpg][]" and do $array['files']['id_cards']['jpg'][] = $value
     *
     * @param mixed $value
     */
    private static function parseKeyAndInsertValueInArray(array &$array, string $key, $value): void
    {
        if (strpos($key, '[') === false) {
            $array[$key] = $value;

            return;
        }

        $parts = explode('[', $key); // files[id_cards][jpg][] => [ 'files',  'id_cards]', 'jpg]', ']' ]
        $pointer = &$array;

        foreach ($parts as $k => $part) {
            if ($k === 0) {
                $pointer = &$pointer[$part];

                continue;
            }

            // Skip two special cases:
            // [[ in the key produces empty string
            // [test : starts with [ but does not end with ]
            if ($part === '' || substr($part, -1) !== ']') {
                // Malformed key, we use it "as is"
                $array[$key] = $value;

                return;
            }

            $part = substr($part, 0, -1); // The last char is a ] => remove it to have the real key

            if ($part === '') { // [] case
                $pointer = &$pointer[];
            } else {
                $pointer = &$pointer[$part];
            }
        }

        $pointer = $value;
    }

    /**
     * Parse the username and password from the `Authorization` header.
     * Only "Basic" is supported for now.
     *
     * @return array{string, string}|array{null, null}
     */
    protected static function parseBasicAuthorization(array $headers): array
    {
        $authorization = trim($headers['authorization'][0] ?? '');

        if (! str_starts_with($authorization, 'Basic ')) {
            return [null, null];
        }

        $auth = base64_decode(trim(explode(' ', $authorization)[1]));

        if (! $auth || ! strpos($auth, ':')) {
            return [null, null];
        }

        return explode(':', $auth, 1);
    }
}
