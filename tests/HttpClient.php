<?php
declare(strict_types=1);

/** Minimal cookie-aware HTTP client for end-to-end tests (captures CSRF tokens automatically). */
final class Client
{
    private array $cookies = [];
    public int $status = 0;
    public array $headers = [];
    public string $body = '';

    public function __construct(private string $base)
    {
    }

    public function get(string $path, array $headers = []): self
    {
        return $this->request('GET', $path, null, $headers);
    }

    public function post(string $path, array $data = [], bool $withCsrf = true, array $headers = []): self
    {
        if ($withCsrf && !isset($data['_csrf'])) {
            $data['_csrf'] = $this->csrf();
        }
        return $this->request('POST', $path, http_build_query($data), $headers);
    }

    private string $token = '';
    public string $loginRedirect = '';

    public function forgetToken(): void
    {
        $this->token = '';
    }

    /** Token captured from the last page that contained one (form field or meta tag). */
    public function csrf(): string
    {
        if ($this->token === '') {
            $this->get('/login');
        }
        return $this->token;
    }

    public function sessionId(): string
    {
        return $this->cookies['gcsess'] ?? '';
    }

    public function request(string $method, string $path, ?string $body = null, array $headers = []): self
    {
        $ch = curl_init($this->base . $path);
        $cookie = implode('; ', array_map(fn ($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        $h = $headers;
        if ($cookie) {
            $h[] = 'Cookie: ' . $cookie;
        }
        if ($body !== null) {
            $h[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = (string) curl_exec($ch);
        $this->status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $this->headers = [];
        foreach (explode("\r\n", substr($raw, 0, $headerSize)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $k = strtolower(trim($k));
                $this->headers[$k] = trim($v);
                if ($k === 'set-cookie' && preg_match('/^([^=]+)=([^;]*)/', trim($v), $m)) {
                    if ($m[2] === '' || str_contains(strtolower($v), 'expires=thu, 01 jan 1970') || str_contains($v, 'Max-Age=0')) {
                        unset($this->cookies[$m[1]]);
                    } else {
                        $this->cookies[$m[1]] = $m[2];
                    }
                }
            }
        }
        $this->body = substr($raw, $headerSize);
        if (preg_match('/(?:name="_csrf" value|name="csrf-token" content)="([a-f0-9]{64})"/', $this->body, $m)) {
            $this->token = $m[1];
        }
        return $this;
    }

    public function location(): string
    {
        return $this->headers['location'] ?? '';
    }
}
