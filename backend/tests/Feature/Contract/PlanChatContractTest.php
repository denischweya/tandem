<?php

declare(strict_types=1);

/** Decoded as mixed deliberately: every read below narrows what it actually uses. */
function openApiDocument(): mixed
{
    return json_decode((string) file_get_contents(base_path('openapi.json')), true);
}

it('publishes a 4000-character cap on a sent message\'s body', function (): void {
    expect(data_get(openApiDocument(), 'components.schemas.SendMessageRequest.properties.body.maxLength'))
        ->toBe(4000);
});

it('publishes the five-emoji enum on a reaction', function (): void {
    expect(data_get(openApiDocument(), 'components.schemas.AddReactionRequest.properties.reaction.enum'))
        ->toEqualCanonicalizing(['👍', '❤️', '😂', '🎉', '❓']);
});
