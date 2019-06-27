<?php

namespace Rennokki\Schedule\Traits;

use Carbon\Carbon;
use Rennokki\Schedule\TimeRange;

trait HasSchedule
{
    protected static $availableDays = [
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday',
        'saturday', 'sunday',
    ];

    protected $carbonInstance = Carbon::class;

    /**
     * Returns a morphOne relationship class of the schedule.
     *
     * @return morphOne The relatinship.
     */
    public function schedule()
    {
        return $this->morphOne(config('schedule.model'), 'model');
    }

    /**
     * Get the Schedule array or null if it doesn't have.
     *
     * @return array|null The array with schedules or null.
     */
    public function getSchedule(string $dateOrDay = null): ?array
    {
        if (!$this->hasSchedule()) {
            return null;
        }

        $schedules = $this->schedule()->first()->schedule;

        if (is_null($dateOrDay)) {
            return $schedules;
        }

        if (in_array($dateOrDay, self::$availableDays)) {
            $dayFromDate = strtolower($this->carbonInstance::parse($dateOrDay)->format('l'));

            return $schedules[$dayFromDate];
        }

        if (!isset($schedules[$dateOrDay])) {
            $dayFromDate = strtolower($this->carbonInstance::parse($dateOrDay)->format('l'));

            $selectedSchedule = $schedules[$dayFromDate];

            if (!is_null($selectedSchedule['start_date']) && !$this->checkDateIsGreaterOrEquals($selectedSchedule['start_date'], $dateOrDay)) {
                return null;
            }

            return $selectedSchedule;
        }

        $selectedSchedule = $schedules[$dateOrDay];

        if (is_null($selectedSchedule['start_date'])) {
            return $selectedSchedule;
        }

        if (!$this->checkDateIsGreaterOrEquals($selectedSchedule['start_date'], $dateOrDay)) {
            return null;
        }

        return $selectedSchedule;
    }

    /**
     * Get the Exclusions array or null if it doesn't have.
     *
     * @return array The array with exclusions.
     */
    public function getExclusions(): array
    {
        return ($this->hasSchedule()) ? ($this->schedule()->first()->exclusions) ?: [] : [];
    }

    /**
     * Check if the model has a schedule set.
     *
     * @return bool If the binded model has a schedule already set.
     */
    public function hasSchedule(): bool
    {
        return (bool)!is_null($this->schedule()->first());
    }

    /**
     * Set a new schedule.
     *
     * @param array $scheduleArray The array with schedules.
     * @return array The schedule array.
     */
    public function setSchedule(array $scheduleArray = [], string $startDate = null)
    {
        if ($this->hasSchedule()) {
            return $this->updateSchedule($scheduleArray, $startDate);
        }

        $model = config('schedule.model');

        $this->schedule()->save(new $model([
            'schedule' => $this->normalizeScheduleArray($scheduleArray, $startDate),
        ]));

        return $this->getSchedule();
    }

    public function addSchedule(array $newSchedules)
    {
        $schedulesArray = $this->getSchedule();

        foreach ($newSchedules as $day => $newSchedule) {
            if (!isset($schedulesArray[$day])) {
                $schedulesArray[$day]['times'] = $newSchedule;
                $schedulesArray[$day]['start_date'] = null;
            } else {
                $schedulesArray[$day]['times'] = array_push(
                    $schedulesArray[$day]['times'],
                    $newSchedule
                );
            }
        }

        $normalizedArray = $this->normalizeScheduleArray($schedulesArray);

        $this->schedule()->first()->update([
            'schedule' => $normalizedArray,
        ]);
    }

    /**
     * Update the model's schedule.
     *
     * @param array $scheduleArray The array with schedules that should be replaced.
     * @return array The schedule array.
     */
    public function updateSchedule(array $scheduleArray, string $startDate = null)
    {
        $this->schedule()->first()->update([
            'schedule' => $this->normalizeScheduleArray($scheduleArray, $startDate),
        ]);

        return $this->getSchedule();
    }

    /**
     * Set exclusions.
     *
     * @param array $exclusionsArray The array with exclusions.
     * @return array The exclusions array.
     */
    public function setExclusions(array $exclusionsArray = [])
    {
        if (!$this->hasSchedule()) {
            return false;
        }

        $this->schedule()->first()->update([
            'exclusions' => $this->normalizeExclusionsArray($exclusionsArray),
        ]);

        return $this->getExclusions();
    }

    /**
     * Update exclusions (alias for setExclusions).
     *
     * @param array $exclusionsArray The array with exclusions.
     * @return array The exclusions array.
     */
    public function updateExclusions(array $exclusionsArray)
    {
        return $this->setExclusions($exclusionsArray);
    }

    /**
     * Delete the schedule of this model.
     *
     * @return bool Wether the schedule was deleted or not.
     */
    public function deleteSchedule(): bool
    {
        return (bool)$this->schedule()->delete();
    }

    /**
     * Delete the exclusions of this model.
     *
     * @return bool|array Wether the exclusions were cleared or not.
     */
    public function deleteExclusions()
    {
        if (!$this->hasSchedule()) {
            return false;
        }

        $this->schedule()->first()->update([
            'exclusions' => null,
        ]);

        return $this->getExclusions();
    }

    /**
     * Check if the model is available on a certain day/date.
     *
     * @param string|Carbon|DateTime $dateOrDay The datetime, date or the day.
     * @return bool Wether it is available on that day.
     */
    public function isAvailableOn($dateOrDay): bool
    {
        if (in_array($dateOrDay, Self::$availableDays)) {
            return (bool)(count($this->getSchedule($dateOrDay)['times']) > 0);
        }

        if ($dateOrDay instanceof $this->carbonInstance) {
            if ($this->isExcludedOn($dateOrDay->toDateString())) {
                if (count($this->getExcludedTimeRangesOn($dateOrDay->toDateString())) != 0) {
                    return true;
                }

                return false;
            }

            return (bool)(count($this->getSchedule(strtolower($this->carbonInstance::parse($dateOrDay)->format('l')))['times']) > 0);
        }

        if ($this->isValidMonthDay($dateOrDay) || $this->isValidYearMonthDay($dateOrDay)) {
            if ($this->isExcludedOn($dateOrDay)) {
                if (count($this->getExcludedTimeRangesOn($dateOrDay)) != 0) {
                    return true;
                }

                return false;
            }

            if (is_null($this->getSchedule($dateOrDay))) {
                return false;
            }

            return (bool)(count($this->getSchedule($dateOrDay)['times']) > 0);
        }

        return false;
    }

    /**
     * Check if the model is unavailable on a certain day/date.
     *
     * @param string|Carbon|DateTime $dateOrDay The datetime, date or the day.
     * @return bool Wether it is unavailable on that day.
     */
    public function isUnavailableOn($dateOrDay): bool
    {
        return (bool)!$this->isAvailableOn($dateOrDay);
    }

    /**
     * Check if the model is available on a certain day/date and time.
     *
     * @param string|Carbon|DateTime $dateOrDay The datetime, date or the day.
     * @param string The time.
     * @return bool Wether it is available on that day, at a certain time.
     */
    public function isAvailableOnAt($dateOrDay, $time): bool
    {
        $timeRanges = null;

        if (in_array($dateOrDay, Self::$availableDays)) {
            $timeRanges = $this->getSchedule($dateOrDay)['times'];
        }

        if ($dateOrDay instanceof $this->carbonInstance) {
            $timeRanges = $this->getSchedule(strtolower($this->carbonInstance::parse($dateOrDay)->format('l')))['times'];

            if ($this->isExcludedOn($dateOrDay->toDateString())) {
                $timeRanges = $this->getExcludedTimeRangesOn($dateOrDay->toDateString());
            }
        }

        if ($this->isValidMonthDay($dateOrDay) || $this->isValidYearMonthDay($dateOrDay)) {
            $timeRanges = $this->getSchedule($dateOrDay)['times'];

            if ($this->isExcludedOn($dateOrDay)) {
                $timeRanges = $this->getExcludedTimeRangesOn($dateOrDay);
            }
        }

        if (!$timeRanges) {
            return false;
        }

        foreach ($timeRanges as $timeRange) {
            $timeRange = new TimeRange($timeRange, $this->carbonInstance);

            if ($timeRange->isInTimeRange($time)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the model is unavailable on a certain day/date and time.
     *
     * @param string|Carbon|DateTime $dateOrDay The datetime, date or the day.
     * @param string The time.
     * @return bool Wether it is unavailable on that day, at a certain time.
     */
    public function isUnavailableOnAt($dateOrDay, $time): bool
    {
        return (bool)!$this->isAvailableOnAt($dateOrDay, $time);
    }

    /**
     * Get the amount of hours on a certain day.
     *
     * @param string|Carbon|DateTime $dateOrDay The datetime, date or the day.
     * @return int The amount of hours on that day.
     */
    public function getHoursOn($dateOrDay): int
    {
        $totalHours = 0;
        $timeRanges = null;

        if (in_array($dateOrDay, Self::$availableDays)) {
            $timeRanges = $this->getSchedule($dateOrDay)['times'];
        }

        if ($dateOrDay instanceof $this->carbonInstance) {
            $timeRanges = $this->getSchedule(strtolower($this->carbonInstance::parse($dateOrDay)->format('l')))['times'];

            if ($this->isExcludedOn($dateOrDay->toDateString())) {
                $timeRanges = $this->getExcludedTimeRangesOn($dateOrDay->toDateString());
            }
        }

        if ($this->isValidMonthDay($dateOrDay) || $this->isValidYearMonthDay($dateOrDay)) {
            $timeRanges = $this->getSchedule(strtolower($this->getCarbonDateFromString($dateOrDay)->format('l')))['times'];

            if ($this->isExcludedOn($dateOrDay)) {
                $timeRanges = $this->getExcludedTimeRangesOn($dateOrDay);
            }
        }

        if (!$timeRanges) {
            return 0;
        }

        foreach ($timeRanges as $timeRange) {
            $timeRange = new TimeRange($timeRange, $this->carbonInstance);

            $totalHours += (int)$timeRange->diffInHours();
        }

        return (int)$totalHours;
    }

    /**
     * Get the amount of minutes on a certain day.
     *
     * @param string|Carbon|DateTime $dateOrDay The datetime, date or the day.
     * @return int The amount of minutes on that day.
     */
    public function getMinutesOn($dateOrDay): int
    {
        $totalMinutes = 0;
        $timeRanges = null;

        if (in_array($dateOrDay, Self::$availableDays)) {
            $timeRanges = $this->getSchedule($dateOrDay)['times'];
        }

        if ($dateOrDay instanceof $this->carbonInstance) {
            $timeRanges = $this->getSchedule(strtolower($this->carbonInstance::parse($dateOrDay)->format('l')))['times'];

            if ($this->isExcludedOn($dateOrDay->toDateString())) {
                $timeRanges = $this->getExcludedTimeRangesOn($dateOrDay->toDateString());
            }
        }

        if ($this->isValidMonthDay($dateOrDay) || $this->isValidYearMonthDay($dateOrDay)) {
            $timeRanges = $this->getSchedule(strtolower($this->getCarbonDateFromString($dateOrDay)->format('l')))['times'];

            if ($this->isExcludedOn($dateOrDay)) {
                $timeRanges = $this->getExcludedTimeRangesOn($dateOrDay);
            }
        }

        if (!$timeRanges) {
            return 0;
        }

        foreach ($timeRanges as $timeRange) {
            $timeRange = new TimeRange($timeRange, $this->carbonInstance);

            $totalMinutes += (int)$timeRange->diffInMinutes();
        }

        return (int)$totalMinutes;
    }

    /**
     * Get the time ranges for a particular excluded date.
     */
    protected function getExcludedTimeRangesOn($date): array
    {
        foreach ($this->getExclusions() as $day => $timeRanges) {
            $carbonDay = $this->getCarbonDateFromString($day);
            $carbonDate = $this->getCarbonDateFromString($date);

            if ($carbonDate->equalTo($carbonDay)) {
                return $timeRanges;
            }
        }

        return [];
    }

    /**
     * Check if the model is excluded on a date.
     */
    protected function isExcludedOn($date): bool
    {
        foreach ($this->getExclusions() as $day => $timeRanges) {
            $carbonDay = $this->getCarbonDateFromString($day);
            $carbonDate = $this->getCarbonDateFromString($date);

            if ($carbonDate->equalTo($carbonDay)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the model is excluded on a date and time.
     */
    protected function isExcludedOnAt($date, $time): bool
    {
        foreach ($this->getExclusions() as $day => $timeRanges) {
            if ($this->isValidMonthDay($day) && $this->isValidMonthDay($date)) {
                $currentDay = $this->carbonInstance::createFromFormat('m-d', $day);
                $currentDate = $this->carbonInstance::createFromFormat('m-d', $date);
            }

            if ($this->isValidYearMonthDay($day) && $this->isValidYearMonthDay($date)) {
                $currentDay = $this->carbonInstance::createFromFormat('Y-m-d', $day);
                $currentDate = $this->carbonInstance::createFromFormat('Y-m-d', $date);
            }

            if ($currentDay->equalTo($currentDate)) {
                foreach ($timeRanges as $timeRange) {
                    $timeRange = new TimeRange($timeRange, $this->carbonInstance);

                    if ($timeRange->isInTimeRange($time)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Normalize the schedule array.
     */
    protected function normalizeScheduleArray(array $scheduleArray, string $startDate = null): array
    {
        $finalScheduleArray = [];

        foreach (Self::$availableDays as $availableDay) {
            $finalScheduleArray[$availableDay] = [
                'times' => [],
                'start_date' => null
            ];
        }

        foreach ($scheduleArray as $day => $timeArray) {
            if (!in_array($day, Self::$availableDays) && !$this->isValidYearMonthDay($day)) {
                continue;
            }

            if (!is_array($timeArray)) {
                continue;
            }

            foreach ($timeArray as $time) {
                $timeRange = new TimeRange($time, $this->carbonInstance);

                if (!$timeRange->isValidTimeRange()) {
                    continue;
                }

                $finalScheduleArray[$day]['times'][] = $time;
                $finalScheduleArray[$day]['start_date'] = $startDate;
            }
        }

        return (array)$finalScheduleArray;
    }

    /**
     * Normalize the exclusions array.
     */
    protected function normalizeExclusionsArray($exclusionsArray): array
    {
        $finalExclusionsArray = [];

        foreach ($exclusionsArray as $day => $timeArray) {
            if (!$this->isValidMonthDay($day) && !$this->isValidYearMonthDay($day)) {
                continue;
            }

            if (!is_array($timeArray)) {
                continue;
            }

            if (count($timeArray) == 0) {
                $finalExclusionsArray[$day] = [];
                continue;
            }

            foreach ($timeArray as $time) {
                $timeRange = new TimeRange($time, $this->carbonInstance);

                if (!$timeRange->isValidTimeRange()) {
                    continue;
                }

                $finalExclusionsArray[$day][] = $time;
            }
        }

        return (array)$finalExclusionsArray;
    }

    protected function isValidMonthDay($dateString): bool
    {
        try {
            $day = $this->carbonInstance::createFromFormat('m-d', $dateString);
        } catch (\Exception $e) {
            return false;
        }

        return (bool)($day && $day->format('m-d') === $dateString);
    }

    protected function isValidYearMonthDay($dateString): bool
    {
        try {
            $day = $this->carbonInstance::createFromFormat('Y-m-d', $dateString);
        } catch (\Exception $e) {
            return false;
        }

        return (bool)($day && $day->format('Y-m-d') === $dateString);
    }

    protected function getCarbonDateFromString($dateString)
    {
        if ($this->isValidMonthDay($dateString)) {
            return $this->carbonInstance::createFromFormat('m-d', $dateString);
        }

        if ($this->isValidYearMonthDay($dateString)) {
            return $this->carbonInstance::createFromFormat('Y-m-d', $dateString);
        }
    }

    protected function checkDateIsGreaterOrEquals($startDate, $desiredDate)
    {
        $startDate = $this->carbonInstance::createFromFormat('Y-m-d', $startDate);
        $desiredDate = $this->carbonInstance::createFromFormat('Y-m-d', $desiredDate);

        return $desiredDate->greaterThanOrEqualTo($startDate);
    }
}
