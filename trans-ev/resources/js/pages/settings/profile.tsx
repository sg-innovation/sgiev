import { type BreadcrumbItem, type SharedData } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useRef } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { router } from '@inertiajs/react';
import { Camera, Trash2 } from 'lucide-react';

import DeleteUser from '@/components/delete-user';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Profile settings',
        href: '/settings/profile',
    },
];

export default function Profile({ mustVerifyEmail, status }: { mustVerifyEmail: boolean; status?: string }) {
    const { auth } = usePage<SharedData>().props;
    const getInitials = useInitials();

    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        name: auth.user.name,
        email: auth.user.email,
        city: (auth.user.city as string | null) ?? '',
    });
    const photo = useForm<{ photo: File | null }>({ photo: null });
    const fileInput = useRef<HTMLInputElement>(null);
    const uploadPhoto = (file: File | null) => {
        if (!file) return;
        photo.setData('photo', file);
        photo.transform(() => ({ photo: file }));
        photo.post(route('profile.photo'), { forceFormData: true, preserveScroll: true, onFinish: () => { if (fileInput.current) fileInput.current.value = ''; } });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        patch(route('profile.update'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Profile settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Profile information" description="Update your photo, name, city and email address" />

                    <div className="flex items-center gap-4">
                        <Avatar className="size-20 overflow-hidden rounded-full">
                            <AvatarImage src={auth.user.avatar as string | undefined} alt={auth.user.name} className="object-cover" />
                            <AvatarFallback className="rounded-full bg-neutral-200 text-lg text-black dark:bg-neutral-700 dark:text-white">{getInitials(auth.user.name)}</AvatarFallback>
                        </Avatar>
                        <div className="space-y-2">
                            <div className="flex flex-wrap gap-2">
                                <Button type="button" variant="outline" size="sm" disabled={photo.processing} onClick={() => fileInput.current?.click()}>
                                    <Camera className="size-4" /> {auth.user.avatar ? 'Change photo' : 'Add photo'}
                                </Button>
                                {auth.user.avatar ? (
                                    <Button type="button" variant="ghost" size="sm" onClick={() => router.delete(route('profile.photo.remove'), { preserveScroll: true })}>
                                        <Trash2 className="size-4" /> Remove
                                    </Button>
                                ) : null}
                            </div>
                            <p className="text-xs text-muted-foreground">JPG, PNG or WebP, up to 4 MB. We crop it to a square.</p>
                            <input ref={fileInput} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" aria-label="Profile photo" onChange={(e) => uploadPhoto(e.target.files?.[0] ?? null)} />
                            <InputError message={photo.errors.photo} />
                        </div>
                    </div>

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>

                            <Input
                                id="name"
                                className="mt-1 block w-full"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                                autoComplete="name"
                                placeholder="Full name"
                            />

                            <InputError className="mt-2" message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="city">City</Label>

                            <Input
                                id="city"
                                className="mt-1 block w-full"
                                value={data.city}
                                onChange={(e) => setData('city', e.target.value)}
                                autoComplete="address-level2"
                                placeholder="e.g. Pune"
                                maxLength={80}
                            />
                            <p className="text-xs text-muted-foreground">Used to show stations near you first.</p>

                            <InputError className="mt-2" message={errors.city} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">Email address</Label>

                            <Input
                                id="email"
                                type="email"
                                className="mt-1 block w-full"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                required
                                autoComplete="username"
                                placeholder="Email address"
                            />

                            <InputError className="mt-2" message={errors.email} />
                        </div>

                        {mustVerifyEmail && auth.user.email_verified_at === null && (
                            <div>
                                <p className="mt-2 text-sm text-neutral-800">
                                    Your email address is unverified.
                                    <Link
                                        href={route('verification.send')}
                                        method="post"
                                        as="button"
                                        className="rounded-md text-sm text-neutral-600 underline hover:text-neutral-900 focus:ring-2 focus:ring-offset-2 focus:outline-hidden"
                                    >
                                        Click here to re-send the verification email.
                                    </Link>
                                </p>

                                {status === 'verification-link-sent' && (
                                    <div className="mt-2 text-sm font-medium text-green-600">
                                        A new verification link has been sent to your email address.
                                    </div>
                                )}
                            </div>
                        )}

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>Save</Button>

                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">Saved</p>
                            </Transition>
                        </div>
                    </form>
                </div>

                <DeleteUser />
            </SettingsLayout>
        </AppLayout>
    );
}
