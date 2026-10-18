<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AssessmentResult;
use App\Models\Enrollment;
use App\Models\PointTransaction;
use App\Models\QuranFinalTest;
use App\Models\QuranPartialTest;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\StudentFile;
use App\Models\StudentNote;
use App\Models\StudentPageAchievement;
use App\Services\AccessScopeService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class StudentProgressFilesController extends Controller
{
    public function progress(Request $request, Student $student)
    {
        $this->authorizePermission($request, 'students.view');
        $this->authorizeProgressStudent($request, $student);
        $scopes = app(AccessScopeService::class);
        $student->load(['gradeLevel', 'parentProfile', 'quranCurrentJuz']);
        $enrollments = $scopes->scopeEnrollments(
            Enrollment::query()->where('student_id', $student->id),
            $request->user(),
        )->with(['group.course', 'group.teacher'])->orderByRaw("case when status = 'active' then 0 else 1 end")->latest('enrolled_at')->get();
        $enrollmentIds = $enrollments->pluck('id')->all();

        return response()->json(['data' => [
            'enrollments' => $enrollments->map(fn (Enrollment $enrollment) => $this->enrollmentPayload($enrollment))->values(),
            'profile' => [
                'birth_date' => $student->birth_date?->format('Y-m-d'),
                'grade_level' => $student->gradeLevel ? ['id' => $student->gradeLevel->id, 'name' => $student->gradeLevel->name] : null,
                'id' => $student->id,
                'name' => $student->full_name,
                'parent' => $student->parentProfile ? ['id' => $student->parentProfile->id, 'name' => $student->parentProfile->father_name] : null,
                'photo_endpoint' => $student->photo_path ? '/api/v1/students/'.$student->id.'/photo' : null,
                'quran_current_juz' => $student->quranCurrentJuz?->juz_number,
                'status' => $student->status,
                'student_number' => $student->student_number,
            ],
            'stats' => [
                'assessment_average' => $this->assessmentAverage($request, $student, $enrollmentIds),
                'attendance_days' => $this->attendanceDays($request, $enrollmentIds),
                'files' => $student->files()->count(),
                'memorized_pages' => StudentPageAchievement::query()->where('student_id', $student->id)->distinct()->count('page_no'),
                'notes' => $this->notesCount($request, $student),
                'passed_final_tests' => $this->finalTestsCount($request, $student),
                'passed_partial_tests' => $this->partialTestsCount($request, $student),
                'points' => $this->pointsTotal($request, $student, $enrollmentIds),
            ],
        ]]);
    }

    public function files(Request $request, Student $student)
    {
        $this->authorizePermission($request, 'students.view');
        $this->authorizeStudent($request, $student);

        return response()->json(['data' => $student->files()->with('uploader')->latest()->get()->map(fn (StudentFile $file) => $this->filePayload($file))->values()]);
    }

    public function storeFile(Request $request, Student $student)
    {
        $this->authorizePermission($request, 'students.files.manage');
        $this->authorizeStudent($request, $student);
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'file_type' => ['required', 'string', 'max:50'],
        ]);
        /** @var UploadedFile $upload */
        $upload = $validated['file'];
        $path = $upload->store('students/files/'.$student->id, 'public');

        try {
            $file = StudentFile::query()->create([
                'file_path' => $path,
                'file_type' => $validated['file_type'],
                'original_name' => $upload->getClientOriginalName(),
                'student_id' => $student->id,
                'uploaded_by' => $request->user()->id,
            ]);
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }

        return response()->json(['data' => $this->filePayload($file->load('uploader'))], 201);
    }

    public function downloadFile(Request $request, Student $student, StudentFile $studentFile)
    {
        $this->authorizePermission($request, 'students.view');
        $this->authorizeStudent($request, $student);
        $this->authorizeNestedFile($student, $studentFile);
        abort_unless(Storage::disk('public')->exists($studentFile->file_path), 404);

        return Storage::disk('public')->download($studentFile->file_path, $studentFile->original_name);
    }

    public function destroyFile(Request $request, Student $student, StudentFile $studentFile)
    {
        $this->authorizePermission($request, 'students.files.manage');
        $this->authorizeStudent($request, $student);
        $this->authorizeNestedFile($student, $studentFile);
        $path = $studentFile->file_path;
        $studentFile->delete();
        Storage::disk('public')->delete($path);

        return response()->noContent();
    }

    public function showPhoto(Request $request, Student $student)
    {
        $this->authorizePermission($request, 'students.view');
        $this->authorizeStudent($request, $student);
        abort_unless($student->photo_path && Storage::disk('public')->exists($student->photo_path), 404);

        return Storage::disk('public')->response($student->photo_path);
    }

    public function updatePhoto(Request $request, Student $student)
    {
        $this->authorizePermission($request, 'students.photo.update');
        $this->authorizeStudent($request, $student);
        $validated = $request->validate(['photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('uploads.image_max_kb')]]);
        /** @var UploadedFile $photo */
        $photo = $validated['photo'];
        $path = $photo->store('students/photos/'.$student->id, 'public');
        $oldPath = $student->photo_path;

        try {
            $student->update(['photo_path' => $path]);
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }
        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json(['data' => ['photo_endpoint' => '/api/v1/students/'.$student->id.'/photo']]);
    }

    public function destroyPhoto(Request $request, Student $student)
    {
        $this->authorizePermission($request, 'students.photo.update');
        $this->authorizeStudent($request, $student);
        $path = $student->photo_path;
        $student->update(['photo_path' => null]);
        if ($path) {
            Storage::disk('public')->delete($path);
        }

        return response()->noContent();
    }

    protected function attendanceDays(Request $request, array $enrollmentIds): ?int
    {
        if (! $request->user()->can('attendance.student.view')) {
            return null;
        }

        return app(AccessScopeService::class)->scopeStudentAttendanceRecords(StudentAttendanceRecord::query(), $request->user())
            ->whereIn('enrollment_id', $enrollmentIds)->whereHas('status', fn ($query) => $query->where('is_present', true))
            ->distinct()->count('group_attendance_day_id');
    }

    protected function assessmentAverage(Request $request, Student $student, array $enrollmentIds): ?float
    {
        if (! $request->user()->can('assessment-results.view')) {
            return null;
        }
        $average = app(AccessScopeService::class)->scopeAssessmentResults(AssessmentResult::query(), $request->user())
            ->where('student_id', $student->id)->whereIn('enrollment_id', $enrollmentIds)->whereNotNull('score')->avg('score');

        return $average === null ? null : round((float) $average, 2);
    }

    protected function pointsTotal(Request $request, Student $student, array $enrollmentIds): ?int
    {
        if (! $request->user()->can('points.view')) {
            return null;
        }

        return (int) app(AccessScopeService::class)->scopePointTransactions(PointTransaction::query(), $request->user())
            ->where('student_id', $student->id)->whereIn('enrollment_id', $enrollmentIds)->whereNull('voided_at')->sum('points');
    }

    protected function partialTestsCount(Request $request, Student $student): ?int
    {
        return $request->user()->can('quran-partial-tests.view')
            ? app(AccessScopeService::class)->scopeQuranPartialTests(QuranPartialTest::query(), $request->user())->where('student_id', $student->id)->where('status', 'passed')->count()
            : null;
    }

    protected function finalTestsCount(Request $request, Student $student): ?int
    {
        return $request->user()->can('quran-final-tests.view')
            ? app(AccessScopeService::class)->scopeQuranFinalTests(QuranFinalTest::query(), $request->user())->where('student_id', $student->id)->where('status', 'passed')->count()
            : null;
    }

    protected function notesCount(Request $request, Student $student): ?int
    {
        return $request->user()->can('student-notes.view')
            ? app(AccessScopeService::class)->scopeStudentNotes(StudentNote::query(), $request->user())->where('student_id', $student->id)->count()
            : null;
    }

    protected function enrollmentPayload(Enrollment $enrollment): array
    {
        return ['course' => $enrollment->group?->course ? ['id' => $enrollment->group->course->id, 'name' => $enrollment->group->course->name] : null, 'enrolled_at' => $enrollment->enrolled_at?->format('Y-m-d'), 'group' => $enrollment->group ? ['id' => $enrollment->group->id, 'name' => $enrollment->group->name] : null, 'id' => $enrollment->id, 'left_at' => $enrollment->left_at?->format('Y-m-d'), 'status' => $enrollment->status, 'teacher' => $enrollment->group?->teacher ? ['id' => $enrollment->group->teacher->id, 'name' => $enrollment->group->teacher->full_name] : null];
    }

    protected function filePayload(StudentFile $file): array
    {
        return ['download_endpoint' => '/api/v1/students/'.$file->student_id.'/files/'.$file->id.'/download', 'file_type' => $file->file_type, 'id' => $file->id, 'original_name' => $file->original_name, 'uploaded_at' => $file->created_at?->toIso8601String(), 'uploaded_by' => $file->uploader ? ['id' => $file->uploader->id, 'name' => $file->uploader->name] : null];
    }

    protected function authorizeProgressStudent(Request $request, Student $student): void
    {
        abort_unless(app(AccessScopeService::class)->canAccessStudentProgress($request->user(), $student), 403);
    }

    protected function authorizeStudent(Request $request, Student $student): void
    {
        abort_unless(app(AccessScopeService::class)->canAccessStudent($request->user(), $student), 403);
    }

    protected function authorizeNestedFile(Student $student, StudentFile $file): void
    {
        abort_unless($file->student_id === $student->id, 404);
    }

    protected function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403);
    }
}
