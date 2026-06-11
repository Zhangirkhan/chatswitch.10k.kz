<?php

declare(strict_types=1);

namespace Tests\Unit\Support\PlatformChangelog;

use App\Support\PlatformChangelog\GitCommitPathClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GitCommitPathClassifierTest extends TestCase
{
    private GitCommitPathClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new GitCommitPathClassifier();
    }

    #[DataProvider('internalOnlyPathsProvider')]
    public function test_detects_internal_only_commits(array $paths): void
    {
        $this->assertTrue($this->classifier->isInternalOnly($paths));
    }

    #[DataProvider('mixedPathsProvider')]
    public function test_does_not_mark_mixed_commits_as_internal_only(array $paths): void
    {
        $this->assertFalse($this->classifier->isInternalOnly($paths));
    }

    public static function internalOnlyPathsProvider(): array
    {
        return [
            [['resources/js/Pages/SuperAdmin/PlatformChangelog/Index.vue']],
            [['app/Http/Controllers/SuperAdmin/PlatformChangelogController.php', 'routes/admin.php']],
        ];
    }

    public static function mixedPathsProvider(): array
    {
        return [
            [['resources/js/Pages/SuperAdmin/PlatformChangelog/Index.vue', 'resources/js/Pages/Chats/Show.vue']],
            [[]],
            [['resources/js/Pages/Chats/Show.vue']],
        ];
    }
}
