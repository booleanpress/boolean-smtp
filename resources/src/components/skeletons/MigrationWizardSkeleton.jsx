import { Card, CardContent, CardFooter, CardHeader } from '@/components/ui/card';
import { Item, ItemContent, ItemMedia } from '@/components/ui/item';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';

export default function MigrationWizardSkeleton() {
    return (
        <div className="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500">
            {/* Header */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div className="space-y-2">
                    <Skeleton className="h-8 w-64" />
                    <Skeleton className="h-4 w-full max-w-2xl" />
                    <Skeleton className="h-4 w-full max-w-2xl" />
                </div>
            </div>

            {/* Discovery Flow Container */}
            <Card>
                {/* Step Indicator */}
                <CardHeader>
                    <div className="flex items-center justify-between">
                        {[...Array(3)].map((_, i) => (
                            <div key={i} className="flex items-center flex-1">
                                <Skeleton className="size-10 rounded-full" />
                                {i < 2 && <Skeleton className="h-1 flex-1 mx-4" />}
                            </div>
                        ))}
                    </div>
                </CardHeader>

                {/* Discovery Results */}
                <CardContent className="space-y-4">
                    <Skeleton className="h-6 w-32" />
                    {[...Array(3)].map((_, i) => (
                        <Item key={i} variant="outline">
                            <ItemMedia>
                                <Skeleton className="size-8 rounded-md" />
                            </ItemMedia>
                            <ItemContent>
                                <Skeleton className="h-4 w-32" />
                                <Skeleton className="h-3 w-48" />
                            </ItemContent>
                        </Item>
                    ))}
                </CardContent>

                {/* Action Buttons */}
                <CardFooter className="flex-col items-stretch gap-6">
                    <Separator />
                    <div className="flex gap-3">
                        <Skeleton className="h-10 w-24" />
                        <Skeleton className="h-10 w-32" />
                    </div>
                </CardFooter>
            </Card>
        </div>
    );
}
