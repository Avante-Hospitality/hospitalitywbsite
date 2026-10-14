<?php

/*
 * One entry per form on the site - the PHP counterpart of server/forms.js.
 * The API only accepts these types, so a stray POST to /api/submissions/anything
 * else is rejected rather than stored.
 */

const AVANTE_META_FIELDS = ['_subject', '_cc', '_replyto', '_template', '_captcha', '_next'];
const AVANTE_MAX_FIELD_LENGTH = 2000;
const AVANTE_MAX_FIELDS = 60;
const AVANTE_EMAIL_RE = '/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/';

function avante_forms(): array
{
    return [
        'listing' => [
            'label' => 'New Listing Request',
            'name_field' => 'property_name',
            'email_field' => 'email',
            'reference_fields' => ['bbid', 'egress_reference'],
            'required' => ['property_name', 'contact_person', 'phone', 'email', 'channel_manager'],
        ],
        'loyalty' => [
            'label' => 'Loyalty Program Sign-Up',
            'name_field' => 'property_name',
            'email_field' => 'email',
            'reference_fields' => ['property_reference'],
            'required' => ['property_name', 'contact_person', 'phone', 'email'],
        ],
        'affiliate' => [
            'label' => 'Affiliate Application',
            'name_field' => 'full_name',
            'email_field' => 'email',
            'reference_fields' => [],
            'required' => ['full_name', 'email', 'phone', 'residence_city', 'residence_country', 'promote_via'],
        ],
        'property-affiliate' => [
            'label' => 'Property Application',
            'name_field' => 'property_name',
            'email_field' => 'email',
            'reference_fields' => [],
            'required' => ['property_name', 'contact_person', 'phone', 'email', 'property_city', 'property_country', 'property_type'],
        ],
    ];
}

function avante_form_label(string $formType): string
{
    $forms = avante_forms();
    return isset($forms[$formType]) ? $forms[$formType]['label'] : $formType;
}

/* Mirrors JavaScript's String() for the value types that can arrive in JSON. */
function avante_to_string($value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_scalar($value)) {
        return (string) $value;
    }
    return ''; // arrays and objects never belong in a form field
}

/**
 * Validate and clean a raw request body.
 * Returns ['ok' => bool, 'error' => ?string, 'fields' => array, 'payload' => array].
 */
function avante_validate(string $formType, $body): array
{
    $forms = avante_forms();
    $fail = fn(string $error, array $fields = []) => ['ok' => false, 'error' => $error, 'fields' => $fields, 'payload' => []];

    if (!isset($forms[$formType])) {
        return $fail('Unknown form type');
    }
    if (!is_array($body)) {
        return $fail('Expected a JSON object');
    }
    // A JSON array decodes to a list; only an object is a valid body. An empty
    // list is left alone because JSON {} also decodes to an empty array.
    if (array_is_list($body) && $body !== []) {
        return $fail('Expected a JSON object');
    }
    $form = $forms[$formType];

    $keys = array_values(array_filter(
        array_keys($body),
        fn($key) => !in_array($key, AVANTE_META_FIELDS, true)
    ));
    if (count($keys) > AVANTE_MAX_FIELDS) {
        return $fail('Too many fields');
    }

    $payload = [];
    foreach ($keys as $key) {
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $key)) {
            continue; // ignore junk keys
        }
        $value = avante_to_string($body[$key]);
        if (mb_strlen($value) > AVANTE_MAX_FIELD_LENGTH) {
            $value = mb_substr($value, 0, AVANTE_MAX_FIELD_LENGTH);
        }
        $payload[$key] = trim($value);
    }

    $bad = [];
    foreach ($form['required'] as $field) {
        if (trim((string) ($payload[$field] ?? '')) === '') {
            $bad[] = $field;
        }
    }
    $email = (string) ($payload[$form['email_field']] ?? '');
    if ($email !== '' && !preg_match(AVANTE_EMAIL_RE, $email)) {
        $bad[] = $form['email_field'];
    }

    if ($bad) {
        return $fail('Missing or invalid fields', array_values(array_unique($bad)));
    }
    return ['ok' => true, 'error' => null, 'fields' => [], 'payload' => $payload];
}

function avante_build_subject(string $formType, array $payload): string
{
    $get = fn(string $key) => (string) ($payload[$key] ?? '');

    switch ($formType) {
        case 'listing':
            $subject = 'New Listing Request - ' . $get('property_name') . ' (' . $get('channel_manager') . ')';
            break;
        case 'loyalty':
            $subject = 'Loyalty Program Sign-Up - ' . $get('property_name');
            break;
        case 'affiliate':
            $subject = 'New Affiliate Application - ' . $get('full_name') . ' (' . $get('residence_city') . ', ' . $get('residence_country') . ')';
            break;
        case 'property-affiliate':
            $subject = 'New Property Application - ' . $get('property_name') . ' (' . $get('property_city') . ', ' . $get('property_country') . ')';
            break;
        default:
            $subject = avante_form_label($formType);
    }

    // The subject goes into an email header, so strip anything that could inject one.
    $subject = (string) preg_replace('/[\r\n]+/', ' ', $subject);
    return mb_substr($subject, 0, 200);
}

function avante_meta_for(string $formType, array $payload): array
{
    $form = avante_forms()[$formType];

    $reference = null;
    foreach ($form['reference_fields'] as $field) {
        $value = trim((string) ($payload[$field] ?? ''));
        if ($value !== '') {
            $reference = $value;
            break;
        }
    }

    $name = trim((string) ($payload[$form['name_field']] ?? ''));
    $email = trim((string) ($payload[$form['email_field']] ?? ''));

    return [
        'name' => $name !== '' ? $name : null,
        'email' => $email !== '' ? $email : null,
        'reference' => $reference,
        'label' => $form['label'],
    ];
}
