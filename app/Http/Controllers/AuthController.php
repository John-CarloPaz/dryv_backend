<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeleteAccountRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ForgotPasswordOtpRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\ResetPasswordOtpRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Mail\PasswordResetOtpMail;
use App\Services\Auth\AuthServiceInterface;
use App\Services\Brevo\BrevoTransactionalEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const PASSWORD_OTP_TTL_MINUTES = 10;

    public function __construct(
        private readonly AuthServiceInterface $authService,
    ) {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register(
            name: $request->string('name')->toString(),
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            deviceName: $request->string('device_name')->toString(),
        );

        return response()->json(
            [
                'status' => 'ok',
                'user' => $result['user'],
                'token' => $result['token'],
                'token_type' => 'Bearer',
            ],
            201,
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            deviceName: $request->string('device_name')->toString(),
        );

        return response()->json([
            'status' => 'ok',
            'user' => $result['user'],
            'token' => $result['token'],
            'token_type' => 'Bearer',
        ]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->string('email')->toString();

        // Avoid account enumeration: always respond OK.
        Password::sendResetLink(['email' => $email]);

        return response()->json([
            'status' => 'ok',
        ]);
    }

    public function forgotPasswordOtp(ForgotPasswordOtpRequest $request, BrevoTransactionalEmailService $brevo): JsonResponse
    {
        $email = $request->string('email')->toString();

        // Avoid account enumeration: always respond OK.
        $userExists = DB::table('users')->where('email', $email)->exists();

        if ($userExists) {
            $otp = (string) random_int(100000, 999999);
            $expiresAt = now()->addMinutes(self::PASSWORD_OTP_TTL_MINUTES);

            DB::table('password_reset_otps')->updateOrInsert(
                ['email' => $email],
                [
                    'code_hash' => Hash::make($otp),
                    'expires_at' => $expiresAt,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $html = view('emails.password_reset_otp', [
                'otp' => $otp,
                'ttlMinutes' => self::PASSWORD_OTP_TTL_MINUTES,
            ])->render();

            $brevo->sendTransactionalEmail(
                toEmail: $email,
                subject: 'Your password reset code',
                htmlContent: $html,
                textContent: "Your password reset code is: {$otp}. This code expires in " . self::PASSWORD_OTP_TTL_MINUTES . ' minutes.',
            );
        }

        return response()->json([
            'status' => 'ok',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            [
                'email' => $request->string('email')->toString(),
                'token' => $request->string('token')->toString(),
                'password' => $request->string('password')->toString(),
                'password_confirmation' => $request->string('password_confirmation')->toString(),
            ],
            function ($user) use ($request): void {
                $user->forceFill([
                    'password' => $request->string('password')->toString(),
                ])->save();

                // Remove all tokens after password reset.
                $user->tokens()->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json([
            'status' => 'ok',
        ]);
    }

    public function resetPasswordOtp(ResetPasswordOtpRequest $request): JsonResponse
    {
        $email = $request->string('email')->toString();
        $otp = $request->string('otp')->toString();

        $otpRow = DB::table('password_reset_otps')->where('email', $email)->first();

        $isValid = false;
        if ($otpRow && isset($otpRow->code_hash, $otpRow->expires_at)) {
            $notExpired = now()->lessThanOrEqualTo($otpRow->expires_at);
            $matches = Hash::check($otp, $otpRow->code_hash);
            $isValid = $notExpired && $matches;
        }

        if (!$isValid) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid or expired OTP.'],
            ]);
        }

        /** @var \App\Models\User|null $user */
        $user = \App\Models\User::query()->where('email', $email)->first();

        if (!$user) {
            // If user was deleted after OTP was issued.
            throw ValidationException::withMessages([
                'email' => ['Invalid request.'],
            ]);
        }

        DB::transaction(function () use ($user, $email, $request): void {
            $user->forceFill([
                'password' => $request->string('password')->toString(),
            ])->save();

            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            DB::table('password_reset_otps')->where('email', $email)->delete();
        });

        return response()->json([
            'status' => 'ok',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'user' => $request->user(),
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($request->has('name')) {
            $user->name = $request->string('name')->toString();
        }

        if ($request->has('email')) {
            $user->email = $request->string('email')->toString();
        }

        $user->save();

        return response()->json([
            'status' => 'ok',
            'user' => $user,
        ]);
    }

    public function deleteAccount(DeleteAccountRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $password = $request->string('password')->toString();

        if (!Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Invalid password.'],
            ]);
        }

        DB::transaction(function () use ($user): void {
            // Revoke all tokens.
            $user->tokens()->delete();

            // Remove session records (no FK/cascade).
            DB::table('sessions')->where('user_id', $user->id)->delete();

            // Remove password reset tokens.
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            // Delete the user record.
            $user->delete();
        });

        return response()->json([
            'status' => 'ok',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logoutCurrentToken($request);

        return response()->json([
            'status' => 'ok',
        ]);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->authService->logoutAllTokens($request);

        return response()->json([
            'status' => 'ok',
        ]);
    }
}
