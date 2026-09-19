<?php

declare(strict_types=1);

namespace Essabu\Toli;

use Essabu\Toli\Exception\ToliException;
use Essabu\Toli\Exception\ToliProtocolError;

/** What Toli read: one answer per question, plus enough to replay it. */
final readonly class Reading
{
    /**
     * @param  array<string, Answer>  $answers
     * @param  array{input_tokens: int, output_tokens: int}  $usage
     */
    public function __construct(
        public string $model,
        public array $answers,
        public array $usage,
        public Thresholds $thresholds,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function parse(array $body, string $requestedModel, Thresholds $thresholds): self
    {
        if (! isset($body['answers']) || ! is_array($body['answers'])) {
            throw new ToliProtocolError(
                'Response without "answers": the server replied, but not to the question asked.',
                bodyExcerpt: ToliException::excerpt(json_encode($body, JSON_UNESCAPED_UNICODE) ?: ''),
            );
        }

        $answers = [];
        foreach ($body['answers'] as $name => $raw) {
            if (is_array($raw)) {
                $answers[(string) $name] = Answer::parse((string) $name, $raw, $thresholds);
            }
        }

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        return new self(
            // The model as the SERVER reported it, never echoed from the
            // request: that is what surfaces the day the provider ships an
            // update and you are no longer served the version you pinned.
            model: isset($body['model']) ? (string) $body['model'] : $requestedModel,
            answers: $answers,
            usage: [
                'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            ],
            thresholds: $thresholds,
        );
    }

    public function answer(string $question): Answer
    {
        return $this->answers[$question]
            ?? throw new ToliProtocolError("No answer for \"{$question}\": Toli did not answer that question.");
    }

    /** The route to take for this question: act, propose, or escalate. */
    public function route(string $question): Route
    {
        return $this->answer($question)->route;
    }

    public function value(string $question): string|float
    {
        return $this->answer($question)->value;
    }

    /**
     * Enough to replay every decision: the model that served it, the
     * thresholds that decided, and the whole distribution. The state is not in
     * there.
     *
     * @return array<string, mixed>
     */
    public function toLog(): array
    {
        return [
            'model' => $this->model,
            'thresholds' => $this->thresholds->toArray(),
            'usage' => $this->usage,
            'answers' => array_map(static fn (Answer $a): array => $a->toLog(), $this->answers),
        ];
    }
}
