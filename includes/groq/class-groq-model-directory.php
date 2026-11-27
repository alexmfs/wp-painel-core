<?php
if (!defined('ABSPATH')) exit;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

class Groq_Model_Directory implements ModelMetadataDirectoryInterface
{
    /** @return array<string, ModelMetadata> */
    private static function models(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $capabilities = [
            CapabilityEnum::textGeneration(),
            CapabilityEnum::chatHistory(),
        ];
        $options = [
            new SupportedOption(OptionEnum::inputModalities()),
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::functionDeclarations()),
            new SupportedOption(OptionEnum::customOptions()),
        ];

        $cache = [
            'llama-3.1-8b-instant'    => new ModelMetadata('llama-3.1-8b-instant',    'Llama 3.1 8B Instant',    $capabilities, $options),
            'llama-3.3-70b-versatile' => new ModelMetadata('llama-3.3-70b-versatile', 'Llama 3.3 70B Versatile', $capabilities, $options),
        ];

        return $cache;
    }

    public function listModelMetadata(): array
    {
        return array_values(self::models());
    }

    public function hasModelMetadata(string $modelId): bool
    {
        return isset(self::models()[$modelId]);
    }

    public function getModelMetadata(string $modelId): ModelMetadata
    {
        $models = self::models();
        if (!isset($models[$modelId])) {
            throw new InvalidArgumentException("Groq model not found: {$modelId}");
        }
        return $models[$modelId];
    }
}
