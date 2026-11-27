<?php
if (!defined('ABSPATH')) exit;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

class OpenAI_Model_Directory implements ModelMetadataDirectoryInterface
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
            'gpt-4o'      => new ModelMetadata('gpt-4o',      'GPT-4o',      $capabilities, $options),
            'gpt-4o-mini' => new ModelMetadata('gpt-4o-mini', 'GPT-4o Mini', $capabilities, $options),
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
            throw new InvalidArgumentException("OpenAI model not found: {$modelId}");
        }
        return $models[$modelId];
    }
}
