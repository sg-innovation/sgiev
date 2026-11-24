import { Head, useForm } from '@inertiajs/react';
import { ArrowRight, LoaderCircle, Mail, Phone, User } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PasswordStrength from '@/components/password-strength';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

interface RegisterForm {
    name: string;
    email: string;
    phone: string;
    password: string;
    password_confirmation: string;
    terms: boolean;
}

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm<RegisterForm>({
        name: '',
        email: '',
        phone: '',
        password: '',
        password_confirmation: '',
        terms: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout title="Create your Trans EV account" description="Book chargers, pay online and keep every invoice in one place.">
            <Head title="Register" />
            <form className="flex flex-col gap-6" onSubmit={submit}>
                <div className="grid gap-5">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <IconInput icon={User}>
                        <Input
                            id="name"
                            type="text"
                            required
                            autoFocus
                            tabIndex={1}
                            autoComplete="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            disabled={processing}
                            placeholder="Full name"
                            className="pl-10"
                        />
                        </IconInput>
                        <InputError message={errors.name} className="mt-2" />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email">Email address</Label>
                        <IconInput icon={Mail}>
                        <Input
                            id="email"
                            type="email"
                            required
                            tabIndex={2}
                            autoComplete="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            disabled={processing}
                            placeholder="you@example.com"
                            className="pl-10"
                        />
                        </IconInput>
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="phone">Mobile number</Label>
                        <div className="flex">
                            <span className="flex h-11 items-center gap-1.5 rounded-l-xl border border-r-0 border-input bg-muted px-3 text-sm text-muted-foreground">
                                <Phone className="size-4" /> +91
                            </span>
                            <Input
                                id="phone"
                                type="tel"
                                inputMode="numeric"
                                required
                                maxLength={10}
                                autoComplete="tel-national"
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value.replace(/\D/g, '').slice(0, 10))}
                                disabled={processing}
                                placeholder="98765 43210"
                                className="rounded-l-none"
                            />
                        </div>
                        <InputError message={errors.phone} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password">Password</Label>
                        <PasswordInput
                            id="password"
                            required
                            tabIndex={3}
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            disabled={processing}
                            placeholder="Create a strong password"
                        />
                        <PasswordStrength password={data.password} />
                        <InputError message={errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">Confirm password</Label>
                        <PasswordInput
                            id="password_confirmation"
                            required
                            tabIndex={4}
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            disabled={processing}
                            placeholder="Re-enter password"
                        />
                        <InputError message={errors.password_confirmation} />
                    </div>

                    <div className="grid gap-1">
                        <div className="flex items-start gap-3 text-sm text-muted-foreground">
                            <Checkbox id="terms" checked={data.terms} onCheckedChange={(v) => setData('terms', v === true)} className="mt-0.5" />
                            <Label htmlFor="terms" className="leading-relaxed font-normal">
                                I agree to the <TextLink href="/terms">Terms of Service</TextLink>, <TextLink href="/privacy">Privacy Policy</TextLink> and{' '}
                                <TextLink href="/refund-policy">Refund Policy</TextLink>.
                            </Label>
                        </div>
                        <InputError message={errors.terms} />
                    </div>

                    <Button type="submit" className="h-12 w-full rounded-xl bg-gradient-to-r from-volt-600 to-electric text-base font-semibold text-white shadow-lg shadow-volt/25 hover:brightness-110" tabIndex={5} disabled={processing}>
                        {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : null}
                        Create account
                        {!processing && <ArrowRight className="size-4" />}
                    </Button>
                </div>

                <div className="text-muted-foreground text-center text-sm">
                    Already have an account?{' '}
                    <TextLink href={route('login')} tabIndex={6}>
                        Log in
                    </TextLink>
                </div>
            </form>
        </AuthLayout>
    );
}

function IconInput({ icon: Icon, children }: { icon: typeof User; children: React.ReactNode }) {
    return (
        <div className="relative">
            <Icon className="pointer-events-none absolute top-1/2 left-3.5 z-10 size-4 -translate-y-1/2 text-muted-foreground" />
            {children}
        </div>
    );
}
