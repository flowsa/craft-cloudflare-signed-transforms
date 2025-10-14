# HMAC Signature Reference

This document explains how signatures are generated to ensure compatibility between the Craft plugin and the Cloudflare Worker.

## Signature Format

```
signature = HMAC-SHA256(secret, data)
```

Where `data` is constructed as:
```
url|expires|transforms
```

## Data Construction Rules

### 1. Always Include URL
```
data = url
```

### 2. Add Expiration (if provided)
```
data = url + "|" + expires
```

### 3. Add Transforms (if any)
```
data = url + "|" + expires + "|" + transforms
```

**Note:** If no expiration but transforms are present:
```
data = url + "|" + transforms  // Missing expires means no expiration
```

## Transform Serialization

Transforms are serialized as an alphabetically sorted query string:

```
transforms = "key1=value1&key2=value2&key3=value3"
```

### Example

Given transforms:
```
{
    width: 400,
    height: 300,
    quality: 85,
    format: 'webp',
    fit: 'cover'
}
```

Serialized (alphabetically sorted):
```
"fit=cover&format=webp&height=300&quality=85&width=400"
```

## PHP Implementation (Plugin)

```php
protected function generateSignature(string $secret, string $url, ?int $expires, string $transforms): string
{
    // Build signature data: url|expires|transforms
    $data = $url;

    if ($expires !== null) {
        $data .= "|{$expires}";
    }

    if (!empty($transforms)) {
        $data .= "|{$transforms}";
    }

    return hash_hmac('sha256', $data, $secret);
}

protected function serializeTransforms(Collection $params): string
{
    $parts = [];

    // Sort keys alphabetically
    $sorted = $params->sortKeys();

    foreach ($sorted as $key => $value) {
        if ($value !== null) {
            $parts[] = "{$key}={$value}";
        }
    }

    return implode('&', $parts);
}
```

## JavaScript Implementation (Worker)

```javascript
// Serialize transform parameters into a canonical string (sorted alphabetically)
function serializeTransforms(transforms) {
	const parts = [];

	// Sort keys alphabetically for consistent signature
	const sortedKeys = Object.keys(transforms).sort();

	for (const key of sortedKeys) {
		const value = transforms[key];
		if (value !== undefined) {
			parts.push(`${key}=${value}`);
		}
	}

	return parts.join('&');
}

// Security: Validate HMAC signature for signed URLs
async function validateSignature(
	secret,
	url,
	signature,
	expires,
	transforms
) {
	// Check expiration first if provided
	if (expires) {
		const expirationTime = parseInt(expires, 10);
		const currentTime = Math.floor(Date.now() / 1000);
		if (isNaN(expirationTime) || currentTime > expirationTime) {
			return false;
		}
	}

	// Build signature data including transforms
	let data = url;

	if (expires) {
		data += `|${expires}`;
	}

	if (transforms && Object.keys(transforms).length > 0) {
		const transformString = serializeTransforms(transforms);
		data += `|${transformString}`;
	}

	const encoder = new TextEncoder();
	const keyData = encoder.encode(secret);
	const messageData = encoder.encode(data);

	const cryptoKey = await crypto.subtle.importKey(
		'raw',
		keyData,
		{ name: 'HMAC', hash: 'SHA-256' },
		false,
		['sign']
	);

	const signatureBuffer = await crypto.subtle.sign('HMAC', cryptoKey, messageData);
	const expectedSignature = Array.from(new Uint8Array(signatureBuffer))
		.map(b => b.toString(16).padStart(2, '0'))
		.join('');

	// Constant-time comparison to prevent timing attacks
	if (signature.length !== expectedSignature.length) {
		return false;
	}

	let result = 0;
	for (let i = 0; i < signature.length; i++) {
		result |= signature.charCodeAt(i) ^ expectedSignature.charCodeAt(i);
	}

	return result === 0;
}
```

## Example Signatures

### Example 1: No Expiration, No Transforms
```
URL: https://example.com/image.jpg
Secret: my-secret-key

Data: "https://example.com/image.jpg"
Signature: b5c3e4f... (HMAC-SHA256)
```

### Example 2: With Expiration
```
URL: https://example.com/image.jpg
Expires: 1697289600
Secret: my-secret-key

Data: "https://example.com/image.jpg|1697289600"
Signature: a7d9c2b... (HMAC-SHA256)
```

### Example 3: With Transforms
```
URL: https://example.com/image.jpg
Transforms: {width: 400, height: 300, quality: 85, format: 'webp'}
Secret: my-secret-key

Serialized: "format=webp&height=300&quality=85&width=400"
Data: "https://example.com/image.jpg|format=webp&height=300&quality=85&width=400"
Signature: d3f8a1c... (HMAC-SHA256)
```

### Example 4: With Expiration and Transforms
```
URL: https://example.com/image.jpg
Expires: 1697289600
Transforms: {width: 400, format: 'webp'}
Secret: my-secret-key

Serialized: "format=webp&width=400"
Data: "https://example.com/image.jpg|1697289600|format=webp&width=400"
Signature: e2a9b5d... (HMAC-SHA256)
```

## Testing Signatures

### PHP Test
```php
$url = "https://example.com/image.jpg";
$expires = null;
$transforms = "format=webp&width=400";
$secret = "test-secret";

$data = $url . "|" . $transforms;
$signature = hash_hmac('sha256', $data, $secret);

echo $signature; // Should match worker validation
```

### JavaScript Test
```javascript
const url = "https://example.com/image.jpg";
const expires = null;
const transforms = {width: 400, format: 'webp'};
const secret = "test-secret";

const transformString = "format=webp&width=400"; // Alphabetically sorted
const data = url + "|" + transformString;

// Use Web Crypto API to generate signature
// (see worker implementation above)
```

## Debugging Signature Mismatches

If signatures don't match between plugin and worker:

1. **Check Secret** - Must be identical on both sides
2. **Check Transform Order** - Must be alphabetically sorted
3. **Check Separators** - Use `|` (pipe) not `,` (comma)
4. **Check Encoding** - UTF-8 encoding for all strings
5. **Check Expiration Format** - Unix timestamp (seconds, not milliseconds)
6. **Check Transform Values** - Null values should be excluded

## Security Notes

- Always use constant-time comparison to prevent timing attacks
- Store secrets in environment variables, never in code
- Use strong random keys (32+ bytes)
- Rotate secrets periodically
- Consider using short expiration times for sensitive content

## Generate Strong Secret

```bash
# Node.js
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"

# OpenSSL
openssl rand -hex 32

# PHP
php -r "echo bin2hex(random_bytes(32));"
```
