<?php

declare(strict_types=1);

it('publishes a 4000-character cap on a sent message\'s body', function (): void {
    expect(data_get(openApiDocument(), 'components.schemas.SendMessageRequest.properties.body.maxLength'))
        ->toBe(4000);
});

it('publishes the five-emoji enum on a reaction', function (): void {
    expect(data_get(openApiDocument(), 'components.schemas.AddReactionRequest.properties.reaction.enum'))
        ->toEqualCanonicalizing(['👍', '❤️', '😂', '🎉', '❓']);
});
