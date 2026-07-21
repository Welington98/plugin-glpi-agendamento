<?php

declare(strict_types=1);

namespace GlpiPlugin\Agendamento\Tests\Unit;

use GlpiPlugin\Agendamento\Agendamento;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AgendamentoHelpersTest extends TestCase
{
    private static function invokePrivate(string $method, array $args = [])
    {
        $reflection = new ReflectionMethod(Agendamento::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, ...$args);
    }

    public function testNormalizeDateTimeReturnsNullForEmptyValue(): void
    {
        $this->assertNull(self::invokePrivate('normalizeDateTime', ['']));
    }

    public function testNormalizeDateTimeReturnsNullForInvalidValue(): void
    {
        $this->assertNull(self::invokePrivate('normalizeDateTime', ['not-a-date']));
    }

    public function testNormalizeDateTimeNormalizesToMysqlFormat(): void
    {
        $this->assertSame('2026-07-17 09:00:00', self::invokePrivate('normalizeDateTime', ['2026-07-17 09:00']));
    }

    public function testNullableStringReturnsNullForBlankInput(): void
    {
        $this->assertNull(self::invokePrivate('nullableString', ['   ']));
        $this->assertNull(self::invokePrivate('nullableString', [null]));
    }

    public function testNullableStringTrimsAndReturnsValue(): void
    {
        $this->assertSame('João Silva', self::invokePrivate('nullableString', ['  João Silva  ']));
    }

    public function testFormatDateTimeLabelReturnsDashForInvalidValue(): void
    {
        $this->assertSame('-', self::invokePrivate('formatDateTimeLabel', ['not-a-date']));
    }

    public function testFormatDateTimeLabelFormatsBrazilianStyle(): void
    {
        $this->assertSame('17/07/2026 09:00', self::invokePrivate('formatDateTimeLabel', ['2026-07-17 09:00:00']));
    }

    public function testTimeStringToMinutesParsesHoursAndMinutes(): void
    {
        $this->assertSame(0, self::invokePrivate('timeStringToMinutes', ['']));
        $this->assertSame(0, self::invokePrivate('timeStringToMinutes', ['07']));
        $this->assertSame(90, self::invokePrivate('timeStringToMinutes', ['01:30']));
        $this->assertSame(540, self::invokePrivate('timeStringToMinutes', ['09:00']));
    }

    public function testFormatAvailableSlotBuildsStartEndAndLabel(): void
    {
        $start = mktime(9, 0, 0, 7, 17, 2026);
        $slot = self::invokePrivate('formatAvailableSlot', [$start, 30]);

        $this->assertSame('2026-07-17T09:00', $slot['start']);
        $this->assertSame('2026-07-17T09:30', $slot['end']);
        $this->assertSame('17/07/2026 09:00 - 09:30', $slot['label']);
    }

    public function testGetStatusOptionsHasAllFourStatuses(): void
    {
        $this->assertSame(
            ['agendado', 'confirmado', 'cancelado', 'realizado'],
            array_keys(Agendamento::getStatusOptions())
        );
    }

    public function testGetStatusLabelFallsBackToAgendadoForUnknownStatus(): void
    {
        $this->assertSame(Agendamento::getStatusLabel('agendado'), Agendamento::getStatusLabel('status-invalido'));
    }

    public function testNormalizeStatusAcceptsLegacyAliases(): void
    {
        $this->assertSame('realizado', self::invokePrivate('normalizeStatus', ['concluido']));
        $this->assertSame('realizado', self::invokePrivate('normalizeStatus', ['concluído']));
        $this->assertSame('realizado', self::invokePrivate('normalizeStatus', ['feito']));
    }

    public function testNormalizeStatusIsCaseInsensitiveAndTrimmed(): void
    {
        $this->assertSame('confirmado', self::invokePrivate('normalizeStatus', ['  CONFIRMADO  ']));
    }

    public function testNormalizeStatusFallsBackToAgendadoForUnknownValue(): void
    {
        $this->assertSame('agendado', self::invokePrivate('normalizeStatus', ['inexistente']));
    }

    public function testDiffFieldsReturnsEmptyStringWhenNothingChanged(): void
    {
        $data = [
            'tecnico_nome' => 'João',
            'contato_cliente' => '11999999999',
            'endereco_cliente' => 'Rua A, 1',
            'data_hora_inicio' => '2026-07-17 09:00:00',
            'data_hora_fim' => '2026-07-17 09:30:00',
            'status' => 'agendado',
            'observacoes' => 'Nenhuma',
        ];

        $this->assertSame('', self::invokePrivate('diffFields', [$data, $data]));
    }

    public function testDiffFieldsListsOnlyChangedFields(): void
    {
        $before = ['tecnico_nome' => 'João', 'status' => 'agendado'];
        $after = ['tecnico_nome' => 'Maria', 'status' => 'confirmado'];

        $diff = self::invokePrivate('diffFields', [$before, $after]);

        $this->assertStringContainsString('Técnico: João → Maria', $diff);
        $this->assertStringContainsString('Status: Agendado → Confirmado', $diff);
    }

    public function testBuildTicketSearchWhereWithEmptyTermOnlyFiltersDeleted(): void
    {
        $this->assertSame(['is_deleted' => 0], self::invokePrivate('buildTicketSearchWhere', ['']));
    }

    public function testBuildTicketSearchWhereWithNumericTermMatchesIdOrName(): void
    {
        $where = self::invokePrivate('buildTicketSearchWhere', ['123']);

        $this->assertSame(0, $where['is_deleted']);
        $this->assertSame(['OR' => ['id' => 123, 'name' => ['LIKE', '%123%']]], $where[0]);
    }

    public function testBuildTicketSearchWhereWithTextTermMatchesNameOnly(): void
    {
        $this->assertSame(
            ['is_deleted' => 0, 'name' => ['LIKE', '%impressora%']],
            self::invokePrivate('buildTicketSearchWhere', ['impressora'])
        );
    }

    public function testFormatTicketLabelUsesFallbackForBlankName(): void
    {
        $this->assertSame('#42 - Sem título', self::invokePrivate('formatTicketLabel', [42, '   ']));
    }

    public function testFormatTicketLabelFormatsIdAndName(): void
    {
        $this->assertSame('#42 - Impressora não liga', self::invokePrivate('formatTicketLabel', [42, 'Impressora não liga']));
    }
}
