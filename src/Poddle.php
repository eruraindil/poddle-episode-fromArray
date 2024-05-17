<?php

namespace PhanAn\Poddle;

use Generator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhanAn\Poddle\Enums\PodcastType;
use PhanAn\Poddle\Values\CategoryCollection;
use PhanAn\Poddle\Values\Channel;
use PhanAn\Poddle\Values\ChannelMetadata;
use PhanAn\Poddle\Values\Episode;
use PhanAn\Poddle\Values\EpisodeCollection;
use PhanAn\Poddle\Values\FundingCollection;
use PhanAn\Poddle\Values\TxtCollection;
use Saloon\XmlWrangler\Exceptions\QueryAlreadyReadException;
use Saloon\XmlWrangler\Exceptions\XmlReaderException;
use Saloon\XmlWrangler\XmlReader;
use Throwable;
use VeeWee\Xml\Encoding\Exception\EncodingException;

class Poddle
{
    public readonly XmlReader $xmlReader;

    public function __construct(public readonly string $xml)
    {
        $this->xmlReader = XmlReader::fromString($xml);
    }

    public static function fromUrl(string $url, int $timeoutInSeconds = 30): self
    {
        return new self(Http::timeout($timeoutInSeconds)->get($url)->body());
    }

    public static function fromXml(string $xml): self
    {
        return new self($xml);
    }

    /**
     * @throws EncodingException
     * @throws QueryAlreadyReadException
     * @throws Throwable
     * @throws XmlReaderException
     */
    public function getChannel(): Channel
    {
        return new Channel(
            url: $this->getSoleValue('atom:link@href'),
            title: $this->getSoleValue('title'),
            description: $this->getSoleValue('description'),
            link: $this->getSoleValue('link'),
            language: $this->getSoleValue('language'),
            categories: $this->getCategories(),
            explicit: $this->getSoleValue('itunes:explicit') === 'yes',
            image: $this->getSoleValue('itunes:image@href'),
            metadata: $this->getMetadata()
        );
    }

    public function getEpisodes(): EpisodeCollection
    {
        return new EpisodeCollection(function (): Generator {
            foreach ($this->xmlReader->element('rss.channel.item')->collectLazy() as $item) {
                yield Episode::fromXmlElement($item);
            }
        });
    }

    /**
     * @throws EncodingException
     * @throws QueryAlreadyReadException
     * @throws Throwable
     * @throws XmlReaderException
     */
    private function getSoleValue(string ...$queries): ?string
    {
        foreach ($queries as $query) {
            if (!Str::startsWith('/rss/channel/', $query)) {
                $query = '/rss/channel/'.ltrim($query, '/');
            }

            if (Str::contains($query, '@')) {
                [$query, $attribute] = explode('@', $query, 2);
                $value = $this->xmlReader->xpathElement($query)->first()?->getAttribute($attribute); // @phpstan-ignore-line
            } else {
                $value = $this->xmlReader->xpathValue($query)->first();
            }

            if ($value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @throws EncodingException
     * @throws QueryAlreadyReadException
     * @throws Throwable
     * @throws XmlReaderException
     */
    private function getMetadata(): ChannelMetadata
    {
        return new ChannelMetadata(
            locked: $this->getSoleValue('podcast:locked') === 'yes',
            guid: $this->getSoleValue('guid'),
            author: $this->getSoleValue('itunes:author'),
            copyright: $this->getSoleValue('copyright'),
            txts: $this->getTxts(),
            fundings: $this->getFundings(),
            type: PodcastType::tryFrom($this->getSoleValue('itunes:type') ?? ''),
            complete: $this->getSoleValue('itunes:complete') === 'yes',
        );
    }

    /**
     * @throws EncodingException
     * @throws QueryAlreadyReadException
     * @throws Throwable
     * @throws XmlReaderException
     */
    private function getFundings(): FundingCollection
    {
        return FundingCollection::fromXmlElements(
            $this->xmlReader->element('rss.channel.podcast:funding')->collectLazy()
        );
    }

    /**
     * @throws EncodingException
     * @throws QueryAlreadyReadException
     * @throws Throwable
     * @throws XmlReaderException
     */
    private function getCategories(): CategoryCollection
    {
        return CategoryCollection::fromXmlElements(
            $this->xmlReader->element('rss.channel.itunes:category')->collectLazy()
        );
    }

    /**
     * @throws EncodingException
     * @throws QueryAlreadyReadException
     * @throws Throwable
     * @throws XmlReaderException
     */
    private function getTxts(): TxtCollection
    {
        return TxtCollection::fromXmlElements(
            $this->xmlReader->element('rss.channel.podcast:txt')->collectLazy()
        );
    }
}
