<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Server-side validation. Rules: required, email, max:N, min:N, int, decimal, date, time,
 * phone, in:a,b,c, password. Returns [cleanData, errors]; only declared fields are kept.
 */
final class Validator
{
    public static function make(array $input, array $rules): array
    {
        $clean = [];
        $errors = [];
        foreach ($rules as $field => $ruleString) {
            $fieldRules = explode('|', $ruleString);
            $value = $input[$field] ?? null;
            if (is_array($value)) {
                $errors[$field] = 'Valeur invalide.';
                continue;
            }
            if (is_string($value)) {
                // Strip control characters (except newlines/tabs) and surrounding spaces.
                $value = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value));
            }
            if ($value === null || $value === '') {
                if (in_array('required', $fieldRules, true)) {
                    $errors[$field] = 'Ce champ est obligatoire.';
                }
                $clean[$field] = null;
                continue;
            }
            if (!mb_check_encoding($value, 'UTF-8')) {
                $errors[$field] = 'Encodage invalide.';
                continue;
            }
            foreach ($fieldRules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = match ($name) {
                    'required', 'string' => null,
                    'email' => filter_var($value, FILTER_VALIDATE_EMAIL) && mb_strlen($value) <= 190 ? null : 'Adresse e-mail invalide.',
                    'max' => mb_strlen($value) > (int) $arg ? "Maximum {$arg} caractères." : null,
                    'min' => mb_strlen($value) < (int) $arg ? "Minimum {$arg} caractères." : null,
                    'int' => preg_match('/^\d{1,9}$/', $value) ? null : 'Nombre entier attendu.',
                    'decimal' => preg_match('/^\d{1,9}([.,]\d{1,3})?$/', $value) ? null : 'Montant invalide.',
                    'date' => self::isDate($value, 'Y-m-d') ? null : 'Date invalide.',
                    'time' => self::isDate($value, 'H:i') ? null : 'Heure invalide.',
                    'phone' => preg_match('/^[+0-9 ().-]{6,20}$/', $value) ? null : 'Numéro de téléphone invalide.',
                    'in' => in_array($value, explode(',', (string) $arg), true) ? null : 'Valeur non autorisée.',
                    'password' => self::passwordError($value),
                    default => throw new \LogicException("Règle de validation inconnue : $name"),
                };
                if ($error !== null) {
                    $errors[$field] = $error;
                    break;
                }
            }
            if (in_array('decimal', $fieldRules, true)) {
                $value = str_replace(',', '.', $value);
            }
            if (in_array('email', $fieldRules, true)) {
                $value = mb_strtolower($value);
            }
            $clean[$field] = $value;
        }
        return [$clean, $errors];
    }

    private static function isDate(string $value, string $format): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        return $d !== false && $d->format($format) === $value;
    }

    public static function passwordError(string $value): ?string
    {
        if (strlen($value) < 10) {
            return 'Le mot de passe doit contenir au moins 10 caractères.';
        }
        if (strlen($value) > 128) {
            return 'Mot de passe trop long (128 caractères max).';
        }
        if (!preg_match('/[a-zA-Z]/', $value) || !preg_match('/\d/', $value)) {
            return 'Le mot de passe doit contenir des lettres et des chiffres.';
        }
        $common = ['password123', 'motdepasse1', 'azerty1234', 'qwerty1234', '1234567890a', 'admin12345'];
        if (in_array(strtolower($value), $common, true)) {
            return 'Ce mot de passe est trop courant.';
        }
        return null;
    }
}
