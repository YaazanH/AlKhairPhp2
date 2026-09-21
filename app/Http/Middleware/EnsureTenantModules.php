<?php

namespace App\Http\Middleware;

use App\Services\Landlord\CurrentModuleAccess;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnsureTenantModules
{
    public function handle(Request $request, Closure $next)
    {
        $access = app(CurrentModuleAccess::class);
        $authenticatedRoute = collect($request->route()?->gatherMiddleware() ?? [])
            ->contains(fn ($middleware) => is_string($middleware) && ($middleware === 'auth' || str_starts_with($middleware, 'auth:')));

        if ($authenticatedRoute && $request->user() && ! $request->is('logout', 'api/v1/auth/token')) {
            abort_unless($access->portalAccountAllowed($request->user()), 403, 'module_disabled: parent_portal');
        }

        foreach ($this->requiredModules($request) as $module) {
            $access->ensure($module);
        }

        $response = $next($request);

        if ($response instanceof JsonResponse && $request->is('api/v1/parent/*', 'api/v1/students', 'api/v1/students/*')) {
            $response->setData($access->filterPayload(
                $response->getData(true),
                $request->is('api/v1/parent/activities', 'api/v1/parent/activities/*'),
            ));
        }

        return $response;
    }

    /** @return array<int, string> */
    private function requiredModules(Request $request): array
    {
        $route = $request->route()?->getName() ?? '';
        $path = $request->path();
        $modules = [];

        $routeModules = [
            'parents.' => 'parents',
            'students.' => 'students',
            'student-notes.' => 'students',
            'teachers.' => 'teachers',
            'courses.' => 'classes',
            'groups.' => 'classes',
            'enrollments.' => 'classes',
            'student-attendance.' => 'student_attendance',
            'teacher-attendance.' => 'teacher_attendance',
            'curricula.' => 'curriculum',
            'curriculum-resources.' => 'curriculum',
            'settings.curriculum-subjects' => 'curriculum',
        ];

        foreach ($routeModules as $prefix => $module) {
            if ($route === $prefix || str_starts_with($route, $prefix)) {
                $modules[] = $module;
            }
        }

        if ($route === 'groups.attendance') {
            $modules[] = 'student_attendance';
        }
        if ($route === 'student-attendance.export' || $route === 'reports.exports.attendance') {
            $modules[] = 'student_attendance';
        }
        if ($route === 'teacher-attendance.export') {
            $modules[] = 'teacher_attendance';
        }

        if (preg_match('#^api/v1/students(?:/|$)#', $path)) {
            $modules[] = 'students';
        }
        if (preg_match('#^api/v1/(groups|enrollments)(?:/|$)#', $path)) {
            $modules[] = 'classes';
        }
        if (preg_match('#^api/v1/enrollments/[^/]+/memorization$#', $path)) {
            $modules[] = 'memorization';
        }
        if (preg_match('#^api/v1/enrollments/[^/]+/quran-tests$#', $path)) {
            $modules[] = 'quran_tests';
        }
        if (preg_match('#^api/v1/enrollments/[^/]+/points/manual$#', $path) || str_starts_with($path, 'api/v1/points/')) {
            $modules[] = 'points_rewards';
        }
        if (str_starts_with($path, 'api/v1/assessments/')) {
            $modules[] = 'assessments';
        }
        if (preg_match('#^api/v1/groups/[^/]+/attendance$#', $path)) {
            $modules[] = 'student_attendance';
        }
        if (str_starts_with($path, 'api/v1/student-attendance')) {
            $modules[] = 'student_attendance';
        }
        if (str_starts_with($path, 'api/v1/teacher-attendance')) {
            $modules[] = 'teacher_attendance';
        }
        if ($path === 'api/v1/reports/teachers/daily-summary') {
            $modules[] = 'teachers';
        }

        if ($request->is('api/v1/parent/*') || $route === 'activities.family') {
            $modules[] = 'parent_portal';
            $suffix = basename($path);
            $module = match ($suffix) {
                'attendance' => 'student_attendance',
                'memorization' => 'memorization',
                'points' => 'points_rewards',
                'assessments' => 'assessments',
                'quran-tests' => 'quran_tests',
                default => null,
            };
            if ($request->is('api/v1/parent/invoices', 'api/v1/parent/invoices/*')) {
                $module = 'student_billing';
            } elseif ($request->is('api/v1/parent/activities', 'api/v1/parent/activities/*') || $route === 'activities.family') {
                $module = 'activities';
            }
            if ($module) {
                $modules[] = $module;
            }
        }

        return array_values(array_unique($modules));
    }
}
