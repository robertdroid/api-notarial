<?php

declare(strict_types=1);

namespace App\Core\Http;

class Response
{
    private mixed $body;
    private int $statusCode;
    private array $headers;

    public function __construct(mixed $body = null, int $statusCode = 200, array $headers = [])
    {
        $this->body = $body;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    public static function json(mixed $data, int $statusCode = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        return new self($data, $statusCode, $headers);
    }

    public static function noContent(): self
    {
        return new self(null, 204);
    }

    public static function created(mixed $data = null): self
    {
        return self::json($data, 201);
    }

    public static function badRequest(string $message, ?array $errors = null): self
    {
        $body = ['error' => $message];
        if ($errors !== null) {
            $body['errors'] = $errors;
        }
        return self::json($body, 400);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return self::json(['error' => $message], 401);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return self::json(['error' => $message], 403);
    }

    public static function notFound(string $message = 'Resource not found'): self
    {
        return self::json(['error' => $message], 404);
    }

    public static function methodNotAllowed(): self
    {
        return self::json(['error' => 'Method not allowed'], 405);
    }

    public static function serverError(string $message = 'Internal server error'): self
    {
        return self::json(['error' => $message], 500);
    }

    public static function file(string $path, string $mimeType, int $size): self
    {
        return new self(
            body: null,
            statusCode: 200,
            headers: [
                'Content-Type' => $mimeType,
                'Content-Length' => (string) $size,
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'public, max-age=3600',
                'X-File-Path' => $path,
            ]
        );
    }

    public function getBody(): mixed
    {
        return $this->body;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function withStatus(int $statusCode): self
    {
        $clone = clone $this;
        $clone->statusCode = $statusCode;
        return $clone;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            if ($name === 'X-File-Path') {
                continue;
            }
            header("$name: $value");
        }

        // If it's a file response, send the file content
        if (isset($this->headers['X-File-Path'])) {
            readfile($this->headers['X-File-Path']);
            return;
        }

        if ($this->body !== null) {
            if (is_array($this->body) || is_object($this->body)) {
                echo json_encode($this->body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                echo $this->body;
            }
        }
    }
}
