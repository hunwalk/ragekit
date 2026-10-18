<?php

namespace Calculator;

/**
 * A pocket-calculator state machine: no UI, just keys in, display out.
 * Operators apply left to right (no precedence), like a basic desk calculator.
 */
final class Engine
{
    private string $display = '0';
    private float $accumulator = 0.0;
    private string $operator = '';
    /** True right after an operator or '=': the next digit starts a new number. */
    private bool $fresh = true;
    private bool $error = false;

    public function display(): string
    {
        return $this->display;
    }

    /** The pending calculation, e.g. "12 ×", shown above the display. */
    public function pending(): string
    {
        if ($this->operator === '') {
            return '';
        }
        return self::format($this->accumulator) . ' ' . $this->operator;
    }

    public function press(string $key): void
    {
        if ($key === 'C') {
            $this->clear();
            return;
        }
        if ($this->error) {
            $this->clear();
        }
        // Not ctype_digit(): TypePHP Nano then requires a `ctype` extension it never registers.
        if (strlen($key) === 1 && $key >= '0' && $key <= '9') {
            $this->digit($key);
        } elseif ($key === '.') {
            $this->decimalPoint();
        } elseif ($key === '±') {
            $this->negate();
        } elseif ($key === '%') {
            $this->setValue($this->value() / 100);
        } elseif ($key === '=') {
            $this->equals();
        } else {
            $this->operator($key);
        }
    }

    private function clear(): void
    {
        $this->display = '0';
        $this->accumulator = 0.0;
        $this->operator = '';
        $this->fresh = true;
        $this->error = false;
    }

    private function digit(string $digit): void
    {
        if ($this->fresh || $this->display === '0') {
            $this->display = $digit;
        } elseif (strlen($this->display) < 15) {
            $this->display .= $digit;
        }
        $this->fresh = false;
    }

    private function decimalPoint(): void
    {
        if ($this->fresh) {
            $this->display = '0.';
        } elseif (!str_contains($this->display, '.')) {
            $this->display .= '.';
        }
        $this->fresh = false;
    }

    private function negate(): void
    {
        if ($this->display === '0') {
            return;
        }
        $this->display = str_starts_with($this->display, '-')
            ? substr($this->display, 1)
            : '-' . $this->display;
    }

    private function operator(string $operator): void
    {
        // Pressing two operators in a row just changes the pending one.
        if ($this->operator !== '' && !$this->fresh) {
            $this->equals();
            if ($this->error) {
                return;
            }
        }
        $this->accumulator = $this->value();
        $this->operator = $operator;
        $this->fresh = true;
    }

    private function equals(): void
    {
        if ($this->operator === '') {
            return;
        }
        $left = $this->accumulator;
        $right = $this->value();
        $result = 0.0;
        switch ($this->operator) {
            case '+':
                $result = $left + $right;
                break;
            case '−':
                $result = $left - $right;
                break;
            case '×':
                $result = $left * $right;
                break;
            case '÷':
                if ($right == 0.0) {
                    $this->showError();
                    return;
                }
                $result = $left / $right;
                break;
            default:
                break;
        }
        $this->operator = '';
        $this->setValue($result);
    }

    private function value(): float
    {
        return (float) $this->display;
    }

    private function setValue(float $value): void
    {
        if (is_nan($value) || is_infinite($value)) {
            $this->showError();
            return;
        }
        $this->display = self::format($value);
        $this->fresh = true;
    }

    private function showError(): void
    {
        $this->clear();
        $this->display = 'Error';
        $this->error = true;
    }

    /** 36.0 → "36", 0.1 + 0.2 → "0.3" (12 significant digits hides float noise). */
    private static function format(float $value): string
    {
        $text = sprintf('%.12g', $value);
        return $text === '-0' ? '0' : $text;
    }
}
