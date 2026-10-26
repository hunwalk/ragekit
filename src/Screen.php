<?php

namespace RageKit;

/** A component that fills a window: what App::open() shows. Child components can't be opened. */
abstract class Screen extends Component
{
    /** @internal the input focus() asked for (its ref="…") and how many times focus was asked for */
    public string $focusRef = '';

    /** @internal */
    public int $focusCount = 0;

    private ?Window $host = null;

    /**
     * Gives the keyboard focus to the input marked ref="$ref" in this screen's template (an Entry
     * or Textarea), e.g. from a <Shortcut keys="Ctrl+F" onPress="focus('search')" />. It happens
     * when the window shows the change, after the handler. An input that isn't shown is skipped.
     */
    public function focus(string $ref): void
    {
        $this->focusRef = $ref;
        $this->focusCount++;
    }

    /**
     * The Window class this screen shows in, e.g. `return AppWindow::class;` for a window with its
     * own title bar. Window::class (the default) = an ordinary window with the OS's title bar.
     */
    public static function window(): string
    {
        return Window::class;
    }

    /**
     * The window's stack of screens: `$this->navigator()->push(new DetailsScreen($id))`,
     * `->back()`, `->replace(…)`, `->reset(…)`. It exists once the screen is shown in a window
     * (not yet in the constructor).
     */
    protected function navigator(): Navigator
    {
        return $this->currentWindow()->navigator();
    }

    /** The window showing this screen (an instance of window()). */
    protected function currentWindow(): Window
    {
        if ($this->host === null) {
            $class = \get_class($this);
            $slash = strrpos($class, '\\');
            throw new \LogicException(($slash === false ? $class : substr($class, $slash + 1)) . ': no window yet; it exists once the screen is shown in one');
        }
        return $this->host;
    }

    /** @internal */
    public function useWindow(Window $window): void
    {
        $this->host = $window;
    }

    /** Called when this screen becomes the one the window shows (also after back() returns to it). */
    public function shown(): void
    {
    }

    /** Called when another screen covers this one, or it's dropped from the stack. */
    public function hidden(): void
    {
    }
}
