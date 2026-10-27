<?php

namespace RageKit;

/** A component that fills a window: what App::open() shows. Child components can't be opened. */
abstract class Screen extends Component
{
    /** @internal the input focus() asked for (its ref="…") and how many times focus was asked for */
    public string $focusRef = '';

    /** @internal */
    public int $focusCount = 0;

    /** @internal the ScrollView scrollTo() asked for (its ref="…"), the row, and how many times it was asked */
    public string $scrollRef = '';

    /** @internal */
    public int $scrollRow = -1;

    /** @internal */
    public int $scrollCount = 0;

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
     * Scrolls the ScrollView marked ref="$ref" so that row $row (0 = its @foreach's first item) is
     * in the middle of the view, e.g. the message a reply quotes. It happens when the window shows
     * the change, after the handler; asking again for the same row scrolls again.
     */
    public function scrollTo(string $ref, int $row): void
    {
        $this->scrollRef = $ref;
        $this->scrollRow = $row;
        $this->scrollCount++;
    }

    /**
     * Scrolls the ScrollView marked ref="$ref" to its end, and (with stickToBottom) sticks there
     * again, so rows that come in follow: e.g. after another conversation's messages replaced the
     * list while the user was scrolled up.
     */
    public function scrollToEnd(string $ref): void
    {
        $this->scrollTo($ref, -2);
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
