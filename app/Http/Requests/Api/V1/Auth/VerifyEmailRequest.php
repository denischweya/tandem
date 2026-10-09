<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyEmailRequest extends FormRequest
{
    /**
     * The signature is the authorization. hasValidSignature() recomputes the HMAC from this
     * request's own URL and query, so a tampered or expired link fails here with a 403
     * before any rule runs and before any user is loaded.
     */
    public function authorize(): bool
    {
        return $this->hasValidSignature();
    }

    /**
     * Every parameter is marked @query, and that is load-bearing rather than cosmetic.
     *
     * Scramble publishes a rules() array as a JSON request BODY schema by default. That
     * would document a contract no client can satisfy: Request::hasValidSignature() goes to
     * UrlGenerator::hasCorrectSignature(), which reconstructs the signed original from
     * $request->query() ALONE. A conformant client POSTing {"id":…,"hash":…} as a body
     * therefore produces a reconstructed URL carrying neither value, the HMAC cannot match,
     * authorize() returns false, and the caller gets 403 on every attempt, forever.
     *
     * @query moves each parameter out of the body and into `parameters` with in: query
     * (PhpDocSchemaTransformer sets the isInQuery attribute; RequestBodyExtension then
     * partitions those out, and with no body parameters left it emits no requestBody at
     * all). tests/Feature/Contract/OpenApiContractTest.php asserts that, because the
     * byte-equality gate protects this document's stability and not its truth: a regression
     * here would pass `make verify` while publishing an unusable endpoint.
     *
     * expires and signature are declared because they are part of the contract the client
     * must relay verbatim, and leaving them undocumented is what let the body/query error
     * go unnoticed. Rules on them change no behaviour: authorize() runs before validation,
     * so anything that could fail these rules has already been refused with a 403.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** @query */
            'id' => ['required', 'uuid'],
            /** @query */
            'hash' => ['required', 'string'],
            /** @query */
            'expires' => ['required', 'integer'],
            /** @query */
            'signature' => ['required', 'string'],
        ];
    }
}
