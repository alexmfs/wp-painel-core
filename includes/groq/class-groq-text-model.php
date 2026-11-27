<?php
if (!defined('ABSPATH')) exit;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

class Groq_Text_Model extends AbstractOpenAiCompatibleTextGenerationModel
{
    private const BASE_URL = 'https://api.groq.com/openai/v1';

    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        $url = self::BASE_URL . '/' . ltrim($path, '/');
        return new Request($method, $url, $headers, $data, $this->getRequestOptions());
    }

    // Force sequential tool calls on every Groq request that includes tools.
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $params = parent::prepareGenerateTextParams($prompt);
        if (isset($params['tools'])) {
            $params['parallel_tool_calls'] = false;
        }
        return $params;
    }
}
