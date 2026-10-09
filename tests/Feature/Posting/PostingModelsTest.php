<?php

declare(strict_types=1);

use App\Enums\PostProviderEnum;
use App\Enums\PostStatusEnum;
use App\Enums\RoleEnum;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\YoutubeShort;

it('builds the caption without a duplicated hashtag block nor a tag already in the title', function (): void {
    $short = new YoutubeShort([
        'title' => 'Meu corte #Shorts',
        'hashtags' => ['#shorts', '#Podcast', 'podcast', 'corte', '#corte'],
    ]);

    expect($short->caption())->toBe("Meu corte #Shorts\n\n#Podcast #corte");
});

it('does not repeat a hashtag glued to the title', function (): void {
    $short = new YoutubeShort(['title' => 'Meu corte#podcast', 'hashtags' => ['#podcast', '#viral']]);

    expect($short->caption())->toBe("Meu corte#podcast\n\n#viral");
});

it('leaves no dangling line without title or hashtags', function (): void {
    expect(new YoutubeShort(['title' => null, 'hashtags' => ['#a']])->caption())->toBe('#a')
        ->and(new YoutubeShort(['title' => 'Só o título', 'hashtags' => null])->caption())->toBe('Só o título');
});

it('gives a new account the default provider of its platform', function (): void {
    $youtube = SocialAccount::query()->create(['platform' => 'youtube', 'name' => 'Canal']);
    $tiktok = SocialAccount::query()->create(['platform' => 'tiktok', 'name' => '@conta']);

    expect($youtube->provider)->toBe(PostProviderEnum::YoutubeApi)
        ->and($tiktok->provider)->toBe(PostProviderEnum::TiktokUploader);
});

it('scopes posts to the owner of the account and lets the admin see all', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin);

    $mine = SocialPost::query()->create([
        'youtube_short_id' => YoutubeShort::factory()->create()->id,
        'social_account_id' => SocialAccount::query()->create(['user_id' => $owner->id, 'platform' => 'tiktok', 'name' => '@minha'])->id,
        'scheduled_for' => now(),
        'status' => PostStatusEnum::Scheduled,
    ]);
    SocialPost::query()->create([
        'youtube_short_id' => YoutubeShort::factory()->create()->id,
        'social_account_id' => SocialAccount::query()->create(['user_id' => $other->id, 'platform' => 'tiktok', 'name' => '@outra'])->id,
        'scheduled_for' => now(),
        'status' => PostStatusEnum::Scheduled,
    ]);

    expect(SocialPost::query()->forUser($owner)->pluck('id')->all())->toBe([$mine->id])
        ->and(SocialPost::query()->forUser($admin)->count())->toBe(2);
});
