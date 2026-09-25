import { Skeleton } from "../ui/skeleton";
import { Card, CardContent } from "@/components/ui/card";
import { cn } from "@/lib/utils";

function SettingsRowsSkeleton({ rows = 3, className }) {
    return (
        <div className={cn("space-y-3", className)}>
            {[...Array(rows)].map((_, index) => (
                <div key={index} className="grid gap-3 sm:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)] sm:items-center">
                    <div className="space-y-2">
                        <Skeleton className="h-4 w-40 max-w-full" />
                        <Skeleton className="h-3 w-56 max-w-full" />
                    </div>
                    <Skeleton className="h-10 w-full" />
                </div>
            ))}
        </div>
    );
}

function SectionSkeleton({ rows = 3 }) {
    return (
        <div>
            <Card className="gap-0 overflow-hidden py-0">
                <div className="space-y-2 border-b px-4 py-3 sm:px-5">
                    <Skeleton className="h-5 w-48" />
                    <Skeleton className="h-4 w-96 max-w-full" />
                </div>
                <CardContent className="px-4 py-4 sm:px-5 sm:py-5">
                    <SettingsRowsSkeleton rows={rows} />
                </CardContent>
            </Card>
        </div>
    );
}

export default function SettingsSkeleton() {
    return (
        <div className="mx-auto max-w-7xl space-y-6 pb-8 animate-in fade-in duration-500">
            <div className="space-y-2">
                <Skeleton className="h-7 w-48" />
                <Skeleton className="h-4 w-80 max-w-full" />
            </div>

            <div className="space-y-4">
                <div data-testid="settings-skeleton-card-grid" className="grid grid-cols-1 gap-4 xl:grid-cols-2 xl:items-start">
                    <SectionSkeleton />
                    <SectionSkeleton />
                    <SectionSkeleton />
                    <SectionSkeleton rows={1} />
                </div>

                <div className="flex justify-end border-t pt-4">
                    <Skeleton className="h-10 w-full sm:w-52" />
                </div>
            </div>
        </div>
    );
}
