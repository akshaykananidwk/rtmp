<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Destinations\ConnectorRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $registry = app(ConnectorRegistry::class);
        $platform = (string) $this->input('platform', 'custom_rtmp');
        $definition = $registry->has($platform) ? $registry->definitions()[$platform] : null;

        $rules = [
            'platform' => ['required', Rule::in($registry->platforms())],
            'name' => ['required', 'string', 'max:100'],
            'account_name' => ['nullable', 'string', 'max:100'],
            'stream_endpoint_id' => ['nullable', 'string', Rule::exists('stream_endpoints', 'id')->where('tenant_id', $this->user()->tenant_id)],
            'platform_account_id' => ['nullable', 'string', Rule::exists('platform_accounts', 'id')->where('tenant_id', $this->user()->tenant_id)->where('platform', $platform)],
            'rtmp_url' => ['nullable', 'string', 'max:2048', 'regex:#^rtmps?://[A-Za-z0-9.\-]+(?::\d{1,5})?(?:/[A-Za-z0-9._\-]+)*/?$#'],
            'stream_key' => ['nullable', 'string', 'max:512'],
            'is_enabled' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];

        if ($definition) {
            $hasAccount = $this->filled('platform_account_id');
            foreach ($definition->fields as $f) {
                if (in_array($f['name'], ['rtmp_url', 'stream_key'], true)) {
                    if (($f['required'] ?? false) && ! $hasAccount && ! ($f['name'] === 'stream_key' && $this->route('destination')?->stream_key_encrypted)) {
                        $rules[$f['name']][] = 'required';
                    }

                    continue;
                }
                $rules['opt_'.$f['name']] = ['nullable', 'string', 'max:5000'];
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['rtmp_url.regex' => 'The RTMP URL must look like rtmp://host/app (no stream key in the URL).'];
    }
}
