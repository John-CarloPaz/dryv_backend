# Mobile Auth (Registration & Login)

This project exposes token-based authentication for mobile clients using **Laravel Sanctum personal access tokens**.

## Endpoints

Base path: `/api/auth`

### Register

`POST /api/auth/register`

Body:

```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "Password123!",
  "password_confirmation": "Password123!",
  "device_name": "pixel-7"
}
```

Response (201):

```json
{
  "status": "ok",
  "user": { "id": 1, "name": "John Doe", "email": "john@example.com" },
  "token": "<SANCTUM_TOKEN>",
  "token_type": "Bearer"
}
```

### Login

`POST /api/auth/login`

Body:

```json
{
  "email": "john@example.com",
  "password": "Password123!",
  "device_name": "pixel-7"
}
```

Response (200): same shape as register.

### Me

`GET /api/auth/me`

Headers:

`Authorization: Bearer <SANCTUM_TOKEN>`

### Logout (current token)

`POST /api/auth/logout`

Headers:

`Authorization: Bearer <SANCTUM_TOKEN>`

### Logout (all tokens)

`POST /api/auth/logout-all`

Headers:

`Authorization: Bearer <SANCTUM_TOKEN>`

## Security notes

- Endpoints are rate-limited via `throttle:auth` (per IP and per email).
- Tokens are issued per `device_name`; repeated logins with the same `device_name` replace the previous token for that device.
- The API uses `auth:sanctum` middleware for protected routes.
