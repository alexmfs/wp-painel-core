<?php
if (!defined('ABSPATH')) exit;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

class Groq_Provider_Availability implements ProviderAvailabilityInterface
{
    public function isConfigured(): bool
    {
        return !empty(getenv('GROQ_API_KEY'));
    }
}

class Groq_Provider extends AbstractApiProvider
{
    protected static function baseUrl(): string
    {
        return 'https://api.groq.com/openai/v1';
    }

    protected static function createProviderMetadata(): ProviderMetadata
    {
        return new ProviderMetadata(
            'groq',
            'Groq',
            ProviderTypeEnum::cloud(),
            'https://console.groq.com/keys',
            RequestAuthenticationMethod::apiKey()
        );
    }

    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        return new Groq_Provider_Availability();
    }

    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new Groq_Model_Directory();
    }

    protected static function createModel(ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata): ModelInterface
    {
        return new Groq_Text_Model($modelMetadata, $providerMetadata);
    }
}
