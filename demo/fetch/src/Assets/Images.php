<?php

namespace Demo\Assets;

/** The demo's own images (registered in FetchApp::assets(), embedded at build time). */
final class Images
{
    /** Shown until the owner's avatar has downloaded (and if it can't be). */
    public const AVATAR = 'assets/avatar.svg';
}
