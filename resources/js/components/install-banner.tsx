import { usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { IosInstallInstructions } from '@/components/ios-install-instructions';
import { Button } from '@/components/ui/button';
import { useInstallPrompt } from '@/hooks/use-install-prompt';
import { useTranslation } from '@/hooks/use-translation';

/**
 * A dismissible "add to home screen" prompt for logged-in users on mobile. It floats just above
 * the bottom tab bar (clearing it via `--pool-tab-bar-h`, which is `0` off-mobile) and self-gates:
 * it never shows when already installed, inside an uninstallable webview, after a recent dismissal,
 * or when there's nothing to offer. Android gets the native install prompt; iOS gets the manual
 * "Share → Add to Home Screen" instructions. Mounted in {@link AppSidebarLayout}.
 */
export function InstallBanner() {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const {
        canInstall,
        isStandalone,
        isInstalled,
        isIOS,
        isInAppBrowser,
        dismissed,
        promptInstall,
        dismiss,
    } = useInstallPrompt();
    const [iosOpen, setIosOpen] = useState(false);

    // Mounted only inside the authenticated AppLayout, but guard defensively.
    if (!auth.user) {
        return null;
    }

    // Already installed/standalone, in a webview that can't install, or dismissed within cooldown.
    if (isStandalone || isInstalled || isInAppBrowser || dismissed) {
        return null;
    }

    // Nothing actionable: not Android-installable and not iOS (which gets manual instructions).
    if (!canInstall && !isIOS) {
        return null;
    }

    const needsIosInstructions = isIOS && !canInstall;

    const handleInstall = async () => {
        if (needsIosInstructions) {
            setIosOpen(true);

            return;
        }

        const outcome = await promptInstall();

        if (outcome === 'dismissed') {
            dismiss();
        }
    };

    return (
        <>
            <div
                className="pointer-events-none fixed inset-x-0 z-40 flex justify-center px-3 md:hidden"
                style={{
                    bottom: 'calc(var(--pool-tab-bar-h) + 0.25rem + env(safe-area-inset-bottom, 0px))',
                }}
            >
                <div className="pointer-events-auto flex w-full max-w-md items-center gap-3 rounded-2xl border border-border bg-card/95 p-3 shadow-[var(--sh-lg)] backdrop-blur">
                    <span className="bg-brand-gradient shadow-glow flex size-10 shrink-0 items-center justify-center rounded-xl text-white">
                        <AppLogoIcon className="size-6" />
                    </span>

                    <div className="min-w-0 flex-1">
                        <p className="font-display text-sm font-semibold text-foreground">
                            Brothers Bets
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {t('Install for a faster, full-screen experience.')}
                        </p>
                    </div>

                    <Button
                        size="sm"
                        onClick={handleInstall}
                        className="shrink-0"
                    >
                        {needsIosInstructions
                            ? t('Add to Home Screen')
                            : t('Install app')}
                    </Button>

                    <button
                        type="button"
                        onClick={dismiss}
                        className="press -mr-1 inline-flex size-7 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        <X className="size-4" />
                        <span className="sr-only">{t('Not now')}</span>
                    </button>
                </div>
            </div>

            <IosInstallInstructions open={iosOpen} onOpenChange={setIosOpen} />
        </>
    );
}
