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
     * @param  array<string, string>  $kinds  question name => the kind that was asked,
     *                                        so an answer of a shape this SDK does not
     *                                        know is still read as what it is
     */
    public static function parse(array $body, string $requestedModel, Thresholds $thresholds, array $kinds = []): self
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
                $answers[(string) $name] = Answer::parse((string) $name, $raw, $thresholds, $kinds[(string) $name] ?? null);
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

    /**
     * The reading as the server served it, for a store to keep and `parse()`
     * to read back. The answers go in their WIRE shape, not their parsed one:
     * a store that kept the parsed form would freeze this SDK's reading of
     * it, and a later SDK could not re-read an old row.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $answers = [];
        foreach ($this->answers as $name => $answer) {
            $answers[$name] = $answer->wire();
        }

        return ['model' => $this->model, 'answers' => $answers, 'usage' => $this->usage];
    }

    /** @return array<string, string> question name => kind, for re-parsing a kept reading */
    public function kinds(): array
    {
        $kinds = [];
        foreach ($this->answers as $name => $answer) {
            $kinds[$name] = $answer->kind;
        }

        return $kinds;
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

    /**
     * The answer's value: an option, a number, or — for a kind with no typed
     * reading — the structure the engine returned.
     *
     * @return string|float|array<string, mixed>|null
     */
    public function value(string $question): string|float|array|null
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
