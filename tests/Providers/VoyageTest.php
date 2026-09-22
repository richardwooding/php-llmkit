<?php

declare(strict_types=1);

namespace LlmKit\Tests\Providers;

use LlmKit\Chatter;
use LlmKit\Config;
use LlmKit\EmbedInputType;
use LlmKit\EmbedRequest;
use LlmKit\Exception\MissingApiKeyException;
use LlmKit\Exception\UnsupportedException;
use LlmKit\FilePart;
use LlmKit\Http\MockTransport;
use LlmKit\ImagePart;
use LlmKit\MultimodalEmbedRequest;
use LlmKit\Providers\Voyage\VoyageClient;
use LlmKit\Providers\Voyage\VoyageProvider;
use LlmKit\RerankRequest;
use LlmKit\TextPart;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VoyageClient::class)]
#[CoversClass(VoyageProvider::class)]
final class VoyageTest extends TestCase
{
    private MockTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
    }

    private function client(string $model = 'voyage-3-large'): VoyageClient
    {
        return VoyageClient::create(
            $model,
            (new Config())->withTransport($this->transport)->withApiKey('vo-test'),
        );
    }

    public function testMatchesAndCapabilities(): void
    {
        $provider = new VoyageProvider();
        self::assertTrue($provider->matches('voyage-3-large'));
        self::assertFalse($provider->matches('gpt-5'));

        // Voyage has no chat models, so the client implements no chat capability.
        self::assertNotContains(Chatter::class, class_implements($this->client()));
    }

    public function testMissingKeyFailsBeforeAnyCall(): void
    {
        $this->expectException(MissingApiKeyException::class);
        VoyageClient::create('voyage-3-large', (new Config())->withTransport($this->transport));
    }

    public function testEmbedSortsByIndex(): void
    {
        $this->transport->pushJson([
            'model' => 'voyage-3-large',
            'data' => [
                ['index' => 1, 'embedding' => [0.3]],
                ['index' => 0, 'embedding' => [0.1]],
            ],
            'usage' => ['total_tokens' => 7],
        ]);

        $response = $this->client()->embed(new EmbedRequest(
            ['first', 'second'],
            dimensions: 1024,
            inputType: EmbedInputType::Document,
        ));

        self::assertSame([[0.1], [0.3]], $response->embeddings);
        self::assertSame(7, $response->usage->totalTokens);
        self::assertSame([
            'model' => 'voyage-3-large',
            'input' => ['first', 'second'],
            'input_type' => 'document',
            'output_dimension' => 1024,
            'truncation' => true,
        ], $this->transport->lastBody());
        self::assertSame('https://api.voyageai.com/v1/embeddings', $this->transport->lastRequest()->url);
    }

    public function testMultimodalEmbedding(): void
    {
        $this->transport->pushJson([
            'model' => 'voyage-multimodal-3',
            'data' => [['index' => 0, 'embedding' => [0.5]]],
            'usage' => ['total_tokens' => 11],
        ]);

        $response = $this->client('voyage-multimodal-3')->embedMultimodal(new MultimodalEmbedRequest([
            [
                new TextPart('a chart'),
                new ImagePart('png-bytes', 'image/png'),
                ImagePart::url('https://example.test/a.png'),
                new FilePart('mp4-bytes', 'video/mp4'),
                FilePart::url('https://example.test/a.mp4', 'video/mp4'),
            ],
        ]));

        self::assertSame([[0.5]], $response->embeddings);
        self::assertSame([[
            'content' => [
                ['type' => 'text', 'text' => 'a chart'],
                ['type' => 'image_base64', 'image_base64' => 'data:image/png;base64,' . base64_encode('png-bytes')],
                ['type' => 'image_url', 'image_url' => 'https://example.test/a.png'],
                ['type' => 'video_base64', 'video_base64' => 'data:video/mp4;base64,' . base64_encode('mp4-bytes')],
                ['type' => 'video_url', 'video_url' => 'https://example.test/a.mp4'],
            ],
        ]], $this->transport->lastBody()['inputs']);
        self::assertSame(
            'https://api.voyageai.com/v1/multimodalembeddings',
            $this->transport->lastRequest()->url,
        );
    }

    public function testNonVideoFilesAreRejected(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->client('voyage-multimodal-3')->embedMultimodal(new MultimodalEmbedRequest([
            [new FilePart('%PDF', 'application/pdf')],
        ]));
    }

    public function testRerankUsesTopK(): void
    {
        $this->transport->pushJson([
            'model' => 'rerank-2',
            'data' => [['index' => 1, 'relevance_score' => 0.8]],
            'usage' => ['total_tokens' => 5],
        ]);

        $response = $this->client('voyage-rerank-2')->rerank(new RerankRequest('q', ['a', 'b'], topN: 1));

        self::assertSame(1, $response->results[0]->index);
        self::assertSame(0.8, $response->results[0]->score);
        self::assertSame(1, $this->transport->lastBody()['top_k']);
        self::assertSame(5, $response->usage->totalTokens);
    }
}
