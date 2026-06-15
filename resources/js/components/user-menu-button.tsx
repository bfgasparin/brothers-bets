import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { useTranslation } from '@/hooks/use-translation';
import type { User } from '@/types';

/**
 * The round avatar that opens the account dropdown. Shared between the mobile top nav and the mobile
 * Settings top bar so both surfaces present the exact same account menu.
 */
export function UserMenuButton({ user }: { user: User }) {
    const getInitials = useInitials();
    const { t } = useTranslation();

    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label={t('Account menu')}
                    className="press pointer-events-auto rounded-full p-0.5"
                >
                    <Avatar className="size-9 overflow-hidden rounded-full">
                        <AvatarImage src={user.avatar} alt={user.name} />
                        <AvatarFallback className="rounded-full bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                            {getInitials(user.name)}
                        </AvatarFallback>
                    </Avatar>
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                side="bottom"
                className="min-w-56 rounded-lg [&_[data-slot=dropdown-menu-item]]:py-3"
            >
                <UserMenuContent user={user} />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
