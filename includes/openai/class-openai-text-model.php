<?php
if (!defined('ABSPATH')) exit;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

class OpenAI_Text_Model extends AbstractOpenAiCompatibleTextGenerationModel
{
    private const BASE_URL = 'https://api.openai.com/v1';

    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        $url = self::BASE_URL . '/' . ltrim($path, '/');
        return new Request($method, $url, $headers, $data, $this->getRequestOptions());
    }
}
