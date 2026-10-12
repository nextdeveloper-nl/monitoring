<?php

namespace NextDeveloper\Monitoring\Http\Controllers\NotificationRules;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use NextDeveloper\Monitoring\Http\Controllers\AbstractMonitoringController;
use NextDeveloper\Monitoring\Services\NotificationRulesService;

/**
 * Notification rules: "when a monitoring notification like this arrives, trigger this pusher". They belong to the current
 * account. Validation only; the work is in NotificationRulesService.
 */
class NotificationRulesController extends AbstractMonitoringController
{
    public function index(NotificationRulesService $rules): JsonResponse
    {
        return $this->respond(fn () => ['data' => $rules->list()]);
    }

    public function show(NotificationRulesService $rules, string $ruleId): JsonResponse
    {
        return $this->respond(fn () => ['data' => $rules->get($ruleId)]);
    }

    public function store(Request $request, NotificationRulesService $rules): JsonResponse
    {
        $data = $request->validate($this->rules(true));

        return $this->respond(fn () => ['data' => $rules->create($data)], 201);
    }

    public function update(Request $request, NotificationRulesService $rules, string $ruleId): JsonResponse
    {
        $data = $request->validate($this->rules(false));

        return $this->respond(fn () => ['data' => $rules->update($ruleId, $data)]);
    }

    public function destroy(NotificationRulesService $rules, string $ruleId): JsonResponse
    {
        return $this->respond(function () use ($rules, $ruleId) {
            $rules->delete($ruleId);

            return [];
        }, 204);
    }

    /** What a rule may contain. Empty lists mean "any". */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => "{$required}|string|max:200",
            'pusher_id' => "{$required}|string|uuid",
            'enabled' => 'sometimes|boolean',
            'event_types' => 'sometimes|array|max:20',
            'event_types.*' => ['string', Rule::in(NotificationRulesService::EVENT_TYPES)],
            'severities' => 'sometimes|array|max:2',
            'severities.*' => ['string', Rule::in(NotificationRulesService::SEVERITIES)],
            'alert_kinds' => 'sometimes|array|max:3',
            'alert_kinds.*' => ['string', Rule::in(NotificationRulesService::ALERT_KINDS)],
            'host_ids' => 'sometimes|array|max:100',
            'host_ids.*' => 'string|uuid',
        ];
    }
}
