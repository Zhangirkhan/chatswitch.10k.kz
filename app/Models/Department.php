<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Pivots\DepartmentUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Department extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'parent_id',
        'is_active',
        'work_schedule_enabled',
        'work_schedule_timezone',
        'work_schedule',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'parent_id' => 'integer',
            'work_schedule_enabled' => 'boolean',
            'work_schedule' => 'array',
        ];
    }

    /**
     * Сотрудники отдела (м-к-м через pivot department_user).
     * Один пользователь может состоять в нескольких отделах; «основной» отдел
     * хранится отдельно как `users.department_id` для совместимости с legacy-выборками.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_user')
            ->using(DepartmentUser::class)
            ->withTimestamps();
    }

    public function teamConversation(): HasOne
    {
        return $this->hasOne(TeamConversation::class, 'department_id')
            ->where('type', TeamConversation::TYPE_DEPARTMENT);
    }

    public function chats(): BelongsToMany
    {
        return $this->belongsToMany(Chat::class, 'chat_department')
            ->withTimestamps();
    }

    /**
     * Воронки продаж, подключённые к отделу. Само наличие записи означает
     * «воронка используется этим отделом»; конкретные этапы выбираются отдельно
     * через {@see funnelStages()}.
     */
    public function funnels(): BelongsToMany
    {
        return $this->belongsToMany(Funnel::class, 'department_funnel')
            ->withTimestamps();
    }

    /**
     * Этапы воронок, явно отмеченные для отдела. Контроллер гарантирует, что
     * каждый этап принадлежит одной из {@see funnels()} (без «висячих» строк).
     */
    public function funnelStages(): BelongsToMany
    {
        return $this->belongsToMany(FunnelStage::class, 'department_funnel_stage')
            ->withTimestamps();
    }

    /**
     * Родительский отдел (вышестоящий уровень иерархии). null — корневой отдел.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Прямые дочерние отделы. Для глубокого обхода используйте рекурсивный загрузчик
     * через `with('children.children...')` или утилиту в DepartmentController.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Посты-задачи отдела (раздел «Организация»).
     * Каждый пост — отдельная задача со своим обсуждением (см. {@see DepartmentPost::comments()}).
     */
    public function posts(): HasMany
    {
        return $this->hasMany(DepartmentPost::class);
    }

    /**
     * Идентификаторы всех потомков (рекурсивно). Используется для:
     *  1) защиты от циклов при смене parent_id;
     *  2) подсчёта «совокупных» сотрудников по поддереву (по необходимости).
     *
     * Реализация — широкий обход в памяти по уже загруженной коллекции отделов,
     * чтобы не делать N запросов на каждый уровень вложенности.
     *
     * @param  iterable<int, Department>  $allDepartments
     * @return array<int, int>
     */
    public function descendantIds(iterable $allDepartments): array
    {
        $byParent = [];
        foreach ($allDepartments as $dept) {
            $byParent[(int) ($dept->parent_id ?? 0)][] = (int) $dept->id;
        }

        $result = [];
        $queue = [$this->id];
        while ($queue !== []) {
            $current = (int) array_shift($queue);
            foreach ($byParent[$current] ?? [] as $childId) {
                if (in_array($childId, $result, true)) {
                    continue;
                }
                $result[] = $childId;
                $queue[] = $childId;
            }
        }

        return $result;
    }
}
