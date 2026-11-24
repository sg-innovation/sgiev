import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { Eye, EyeOff } from 'lucide-react';
import { forwardRef, useState, type ComponentProps } from 'react';

/** Password field with a show / hide (eye) toggle. Use for every password input. */
const PasswordInput = forwardRef<HTMLInputElement, Omit<ComponentProps<typeof Input>, 'type'>>(({ className, ...props }, ref) => {
    const [visible, setVisible] = useState(false);

    return (
        <div className="relative">
            <Input ref={ref} type={visible ? 'text' : 'password'} className={cn('pr-11', className)} {...props} />
            <button
                type="button"
                onClick={() => setVisible((v) => !v)}
                className="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-xl text-muted-foreground transition hover:text-foreground focus-visible:text-foreground focus-visible:outline-none"
                aria-label={visible ? 'Hide password' : 'Show password'}
                aria-pressed={visible}
                tabIndex={-1}
            >
                {visible ? <EyeOff className="size-[18px]" /> : <Eye className="size-[18px]" />}
            </button>
        </div>
    );
});
PasswordInput.displayName = 'PasswordInput';

export default PasswordInput;
