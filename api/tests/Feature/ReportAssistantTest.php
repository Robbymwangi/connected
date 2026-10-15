<?php

use App\Models\Mark;
use App\Support\Assistant\ReportAssistant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/* 4.5, the report assistant (ADR 0013). Every model call is faked and stray requests refused: the
   real model's behaviour is measured by `php artisan assistant:evaluate`, not here. These tests fix
   what the server does around the model: the gate fails closed, the loop runs only report tools,
   the checks turn anything doubtful into the fixed reply, history stays on the server, and nothing
   identifying is sent. */

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.anthropic.key' => 'sk-test', 'services.anthropic.base_url' => 'https://anthropic.test']);
});

/** The graph's student at 3 of 10 on a finalized CAT 1, so below the pass mark and on the attention list. */
function assistantGraph(): array
{
    $g = buildGraph('Hillside Academy', '-asst-'.Str::random(5));
    Mark::query()->whereKey($g['mark']->id)->update(['score' => 3]);
    $g['assessment']->finalize($g['teacher']);

    return $g;
}

function ask(mixed $test, array $g, string $question, ?string $conversationId = null, array $scope = [])
{
    return $test->withToken(tokenFor($g['teacher']))->postJson('/api/reports/assistant', [
        'stream' => 'West', 'year' => 2026, 'question' => $question, 'conversation_id' => $conversationId, ...$scope,
    ]);
}

function gateSays(bool $inScope): array
{
    return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode(['in_scope' => $inScope])]]];
}

/* The API sends a tool's input as a JSON object, an empty one for a tool that takes no arguments, so
   the fake does too: a PHP [] would be sent as a list and hide the round trip the real API checks. */
function callsTool(string $name, array $input = [], string $id = 'toolu_1'): array
{
    $input = (object) $input;

    return ['stop_reason' => 'tool_use', 'content' => [
        ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
        ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input],
    ]];
}

function answers(string $text, bool $answerable = true, string $chart = 'none'): array
{
    return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode([
        'answerable' => $answerable, 'text' => $text, 'chart' => $chart, 'figures_used' => [],
    ])]]];
}

function modelReplies(array ...$replies): void
{
    $sequence = Http::sequence();
    foreach ($replies as $reply) {
        $sequence->push($reply);
    }
    Http::fake(['anthropic.test/v1/messages' => $sequence]);
}

test('an in-scope question is answered from a tool, with names restored only for the screen', function () {
    $g = assistantGraph();
    modelReplies(gateSays(true), callsTool('attention_list'), answers('S1 is averaging 30%, below the 50% pass mark.'));

    $response = ask($this, $g, 'Who needs the most support?')->assertOk();

    expect($response->json('data'))->toMatchArray([
        'answerable' => true, 'text' => 'S. Student is averaging 30%, below the 50% pass mark.', 'chart' => null,
    ]);
    $sent = Http::recorded()->map(fn (array $pair) => $pair[0]);
    expect($sent)->toHaveCount(3);
    [$gate, $first, $second] = $sent->all();
    expect($gate['model'])->toBe('claude-haiku-5-5');
    expect($gate->data())->not->toHaveKey('tools');
    expect($gate['thinking'])->toBe(['type' => 'disabled']);
    expect($first['model'])->toBe('claude-sonnet-5-5');
    expect(collect($first['tools'])->pluck('name')->all())->toBe([
        'report_summary', 'trend', 'criterion_breakdown', 'attention_list', 'decline_list', 'student_outcomes',
        'class_standings', 'improvers', 'absences_and_gaps', 'student_across_subjects', 'struggling_in_several_subjects',
        'subject_breakdown', 'term_deviation', 'stream_comparison', 'criterion_across_streams', 'gender_breakdown',
    ]);
    expect(collect($first['tools'])->every(fn (array $tool) => $tool['strict'] === true))->toBeTrue();
    expect($second['messages'][2]['content'][0])->toMatchArray(['type' => 'tool_result', 'tool_use_id' => 'toolu_1']);
    // The model's tool call goes back as the API sent it: an empty input stays an object, never a list.
    expect(json_decode($second->body())->messages[1]->content[1]->input)->toEqual(new stdClass);
    expect($second['messages'][2]['content'][0]['content'])->toContain('"student":"S1"');
    $everything = $sent->map(fn (Request $request) => $request->body())->implode("\n");
    expect($everything)->not->toContain('S. Student')->not->toContain('Hillside Academy')->not->toContain($g['student']->id);
});

test('the gate fails closed: out of scope, an error, or output it cannot read never reaches the main model', function (array|int $gate) {
    $g = assistantGraph();
    Http::fake(['anthropic.test/v1/messages' => is_int($gate) ? Http::response(['error' => 'x'], $gate) : Http::response($gate)]);

    $response = ask($this, $g, 'Write me a poem about the sea.')->assertOk();

    expect($response->json('data'))->toMatchArray(['answerable' => false, 'text' => ReportAssistant::FIXED_REPLY, 'chart' => null]);
    Http::assertSent(fn (Request $request) => $request['model'] === 'claude-haiku-5-5');
    Http::assertNotSent(fn (Request $request) => $request['model'] === 'claude-sonnet-5-5');
})->with([
    'out of scope' => [gateSays(false)],
    'server error' => [500],
    'bad request' => [400],
    'not JSON' => [['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Sure!']]]],
    'refused' => [['stop_reason' => 'refusal', 'content' => []]],
]);

test('a model refusal, an ungrounded figure, a figure-free answer, an unknown handle, or too many tool calls become the fixed reply', function (array $replies) {
    $g = assistantGraph();
    modelReplies(gateSays(true), ...$replies);

    $data = ask($this, $g, 'How did the class do?')->assertOk()->json('data');
    expect($data)->toMatchArray(['answerable' => false, 'text' => ReportAssistant::FIXED_REPLY]);
    // Why it was refused is the evaluation's to record; the screen is never told.
    expect($data)->not->toHaveKey('why')->not->toHaveKey('draft');
})->with([
    'answerable false, in its own words' => [[answers('I would rather talk about football.', answerable: false)]],
    'a number no tool returned' => [[callsTool('report_summary'), answers('The pass rate is 87%.')]],
    'no figure at all' => [[callsTool('report_summary'), answers('The class is doing fine.')]],
    'stopped for length' => [[['stop_reason' => 'max_tokens', 'content' => []]]],
    // The graph's one student is S1 on the attention list; S6 was never returned, so it could not be
    // put back to a name and could as easily be another student's.
    'a handle no tool returned' => [[callsTool('attention_list'), answers('S6 is averaging 30%, below the 50% pass mark.')]],
    'still asking after twelve tools' => [array_map(fn (int $n) => callsTool('trend', [], "toolu_{$n}"), range(1, ReportAssistant::MAX_TOOL_CALLS + 1))],
]);

test('an answer sent before any tool is asked once, and only once, to use the tools', function () {
    $g = assistantGraph();
    modelReplies(gateSays(true), answers('Checking the report now.'), callsTool('report_summary'), answers('The pass mark is 50%.'));

    expect(ask($this, $g, 'What is the pass mark?')->assertOk()->json('data'))->toMatchArray(['answerable' => true, 'text' => 'The pass mark is 50%.']);
    $sent = Http::recorded()->map(fn (array $pair) => $pair[0])->all();
    expect($sent)->toHaveCount(4);
    expect(collect($sent[2]['messages'])->last())->toMatchArray(['role' => 'user', 'content' => 'Call the tools you need now, then give your final answer from their results.']);
    // Every fractional figure reaches the model at one decimal, as the report shows it.
    expect($sent[3]['messages'][4]['content'][0]['content'])->not->toMatch('/\d\.\d{2,}/');
});

test('a model that still answers without a tool after being asked gets the fixed reply', function () {
    $g = assistantGraph();
    modelReplies(gateSays(true), answers('Checking now.'), answers('Still checking.'));

    expect(ask($this, $g, 'What is the pass mark?')->assertOk()->json('data'))->toMatchArray(['answerable' => false, 'text' => ReportAssistant::FIXED_REPLY]);
    expect(Http::recorded())->toHaveCount(3);
});

test('a tool cannot be pointed at a student the conversation has not met', function () {
    $g = assistantGraph();
    modelReplies(gateSays(true), callsTool('student_outcomes', ['student' => 'S7']), answers('S7 is not in this report.'));

    ask($this, $g, 'How did S7 do?')->assertOk();

    $toolResult = Http::recorded()->last()[0]['messages'][2]['content'][0]['content'];
    expect($toolResult)->toContain('S7 is not a student in this conversation');
});

test('history is held by the server in handle form, for the same user and the same report only', function () {
    $g = assistantGraph();
    // One sequence for all three questions: a later Http::fake would not replace an earlier stub.
    modelReplies(
        gateSays(true), callsTool('attention_list'), answers('S1 is averaging 30%.'),
        gateSays(true), callsTool('report_summary'), answers('The pass mark is 50%.'),
        gateSays(true), callsTool('report_summary'), answers('The pass mark is 50%.'),
    );

    $id = ask($this, $g, 'Who needs support?')->json('data.conversationId');
    ask($this, $g, 'And the pass mark?', $id)->assertOk()->assertJsonPath('data.conversationId', $id);

    $messages = Http::recorded()->map(fn (array $pair) => $pair[0])->last()['messages'];
    expect(array_slice($messages, 0, 3))->toBe([
        ['role' => 'user', 'content' => 'Who needs support?'],
        ['role' => 'assistant', 'content' => 'S1 is averaging 30%.'],
        ['role' => 'user', 'content' => 'And the pass mark?'],
    ]);

    $other = makeColleague($g, 'other-asst@example.com');
    $this->app['auth']->forgetGuards();
    $theirs = $this->withToken(tokenFor($other))->postJson('/api/reports/assistant', ['stream' => 'West', 'year' => 2026, 'question' => 'And the pass mark?', 'conversation_id' => $id]);
    expect($theirs->json('data.conversationId'))->not->toBe($id);
    Http::assertSent(fn (Request $request) => $request['model'] === 'claude-sonnet-5-5'
        && $request['messages'] === [['role' => 'user', 'content' => 'And the pass mark?']]);
});

test('a student\'s name typed into the question reaches neither model, and is kept as a handle', function () {
    $g = assistantGraph();
    $g['student']->update(['name' => 'Amina Wanjiru']);
    modelReplies(gateSays(true), callsTool('attention_list'), answers('S1 is averaging 30%.'));

    $id = ask($this, $g, 'How is Amina Wanjiru doing?')->assertOk()->assertJsonPath('data.text', 'Amina Wanjiru is averaging 30%.')->json('data.conversationId');

    $everything = Http::recorded()->map(fn (array $pair) => $pair[0]->body())->implode("\n");
    expect($everything)->toContain('How is S1 doing?')->not->toContain('Amina')->not->toContain('Wanjiru');
    expect(Cache::get("assistant:conversation:{$id}")['turns'][0]['question'])->toBe('How is S1 doing?');
});

test('the scope is the report\'s, validated as the summary validates it', function () {
    $g = assistantGraph();
    buildGraph('Other School', '-asst-other');

    ask($this, $g, 'How did they do?', scope: ['stream' => 'Nowhere'])->assertUnprocessable()->assertJsonValidationErrors('stream');
    ask($this, $g, str_repeat('x', 501))->assertUnprocessable()->assertJsonValidationErrors('question');
    Http::assertNothingSent();
});

test('without a key there is no assistant, and the device is told so', function () {
    config(['services.anthropic.key' => null]);
    $g = assistantGraph();

    ask($this, $g, 'How did the class do?')->assertStatus(503)->assertJsonPath('code', 'assistant_unavailable');
    Http::assertNothingSent();
});

test('a teacher is limited to ten questions a minute', function () {
    config(['services.anthropic.key' => null]);
    $g = assistantGraph();

    foreach (range(1, 10) as $ignored) {
        ask($this, $g, 'How did the class do?')->assertStatus(503);
    }
    ask($this, $g, 'How did the class do?')->assertTooManyRequests();
});

test('the evaluation command needs a key, and passes attacks only on the fixed reply', function () {
    $g = assistantGraph();
    Storage::fake('local');

    config(['services.anthropic.key' => null]);
    $this->artisan('assistant:evaluate')->assertFailed();

    config(['services.anthropic.key' => 'sk-test']);
    Http::fake(['anthropic.test/v1/messages' => Http::response(gateSays(false))]);
    $this->artisan('assistant:evaluate', ['--only' => 'attacks', '--stream' => 'West'])->expectsOutputToContain('The bar is met.')->assertSuccessful();

    $this->artisan('assistant:evaluate', ['--only' => 'ordinary', '--stream' => 'West'])
        ->expectsOutputToContain('(the gate judged it out of scope)')->expectsOutputToContain('The bar is not met.')->assertFailed();
    $files = Storage::disk('local')->allFiles('assistant-evaluation');
    expect($files)->toHaveCount(2);
    $ordinary = collect($files)->first(fn (string $file) => str_contains($file, 'ordinary'));
    expect(json_decode(Storage::disk('local')->get($ordinary), true)['rows'][0])->toMatchArray(['why' => 'the gate judged it out of scope']);
});

test('an evaluation whose calls fail is a failed run, not a set of refusals', function () {
    assistantGraph();
    Storage::fake('local');
    Http::fake(['anthropic.test/v1/messages' => Http::response(['error' => ['type' => 'authentication_error']], 401)]);

    $this->artisan('assistant:evaluate', ['--only' => 'attacks', '--stream' => 'West'])
        ->expectsOutputToContain('failed with an error')->expectsOutputToContain('The bar is not met.')->assertFailed();
});
