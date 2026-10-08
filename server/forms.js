'use strict';

// One entry per form on the site. The API only accepts these types, so a stray
// POST to /api/submissions/anything-else is rejected rather than stored.
const FORMS = {
  listing: {
    label: 'New Listing Request',
    nameField: 'property_name',
    emailField: 'email',
    referenceFields: ['bbid', 'egress_reference'],
    required: ['property_name', 'contact_person', 'phone', 'email', 'channel_manager'],
    subject: (p) => `New Listing Request - ${p.property_name} (${p.channel_manager})`,
  },
  loyalty: {
    label: 'Loyalty Program Sign-Up',
    nameField: 'property_name',
    emailField: 'email',
    referenceFields: ['property_reference'],
    required: ['property_name', 'contact_person', 'phone', 'email'],
    subject: (p) => `Loyalty Program Sign-Up - ${p.property_name}`,
  },
  affiliate: {
    label: 'Affiliate Application',
    nameField: 'full_name',
    emailField: 'email',
    referenceFields: [],
    required: ['full_name', 'email', 'phone', 'residence_city', 'residence_country', 'promote_via'],
    subject: (p) => `New Affiliate Application - ${p.full_name} (${p.residence_city}, ${p.residence_country})`,
  },
  'property-affiliate': {
    label: 'Property Application',
    nameField: 'property_name',
    emailField: 'email',
    referenceFields: [],
    required: ['property_name', 'contact_person', 'phone', 'email', 'property_city', 'property_country', 'property_type'],
    subject: (p) => `New Property Application - ${p.property_name} (${p.property_city}, ${p.property_country})`,
  },
};

// Meta fields the old FormSubmit integration used; harmless if a browser still sends them.
const META_FIELDS = new Set(['_subject', '_cc', '_replyto', '_template', '_captcha', '_next']);

const MAX_FIELD_LENGTH = 2000;
const MAX_FIELDS = 60;
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

function isPlainObject(v) {
  return typeof v === 'object' && v !== null && !Array.isArray(v);
}

/**
 * Validate and clean a raw request body.
 * Returns { ok: true, payload } or { ok: false, error, fields }.
 */
function validate(formType, body) {
  const form = FORMS[formType];
  if (!form) return { ok: false, error: 'Unknown form type', fields: [] };
  if (!isPlainObject(body)) return { ok: false, error: 'Expected a JSON object', fields: [] };

  const keys = Object.keys(body).filter((k) => !META_FIELDS.has(k));
  if (keys.length > MAX_FIELDS) return { ok: false, error: 'Too many fields', fields: [] };

  const payload = {};
  const badFields = [];

  for (const key of keys) {
    if (!/^[A-Za-z0-9_]{1,64}$/.test(key)) continue; // ignore junk keys
    const raw = body[key];
    let value = raw === null || raw === undefined ? '' : String(raw);
    if (value.length > MAX_FIELD_LENGTH) value = value.slice(0, MAX_FIELD_LENGTH);
    value = value.trim();
    payload[key] = value;
  }

  for (const field of form.required) {
    if (!String(payload[field] ?? '').trim()) badFields.push(field);
  }
  const email = String(payload[form.emailField] ?? '');
  if (email && !EMAIL_RE.test(email)) badFields.push(form.emailField);

  if (badFields.length) {
    return { ok: false, error: 'Missing or invalid fields', fields: [...new Set(badFields)] };
  }
  return { ok: true, payload };
}

function buildSubject(formType, payload) {
  const form = FORMS[formType];
  // Subject goes into an email header, so strip anything that could inject one.
  return form.subject(payload).replace(/[\r\n]+/g, ' ').slice(0, 200);
}

function metaFor(formType, payload) {
  const form = FORMS[formType];
  const reference = form.referenceFields
    .map((f) => String(payload[f] ?? '').trim())
    .find((v) => v) || null;
  return {
    name: String(payload[form.nameField] ?? '').trim() || null,
    email: String(payload[form.emailField] ?? '').trim() || null,
    reference,
    label: form.label,
  };
}

module.exports = { FORMS, validate, buildSubject, metaFor, META_FIELDS };
