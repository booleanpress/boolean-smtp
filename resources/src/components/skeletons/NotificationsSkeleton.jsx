import { Skeleton } from "../ui/skeleton";
import { Card, CardContent } from "@/components/ui/card";

export default function NotificationsSkeleton() {
    return (
        <div className="mx-auto max-w-7xl space-y-8 animate-in fade-in duration-500">
            {/* Header */}
            <div className="space-y-2">
                <Skeleton className="h-7 w-56" />
                <Skeleton className="h-4 w-96 max-w-full" />
            </div>

            {/* Available providers */}
            <div className="space-y-4">
                <Skeleton className="h-5 w-44" />
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                    {[...Array(3)].map((_, i) => (
                        <Card key={i}>
                            <CardContent className="space-y-4">
                                <Skeleton className="size-12 rounded-lg" />
                                <div className="space-y-2">
                                    <Skeleton className="h-4 w-24" />
                                    <Skeleton className="h-3 w-full" />
                                    <Skeleton className="h-3 w-3/4" />
                                </div>
                                <Skeleton className="h-3 w-24" />
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>

            {/* Connected channels table */}
            <div className="space-y-4">
                <Skeleton className="h-5 w-44" />
                <div className="overflow-hidden rounded-lg border bg-card">
                    <div className="flex items-center gap-4 border-b bg-muted/40 p-4">
                        <Skeleton className="size-4 rounded-sm" />
                        <Skeleton className="h-3 w-24" />
                        <Skeleton className="ml-auto h-3 w-16" />
                    </div>
                    {[...Array(3)].map((_, i) => (
                        <div key={i} className="flex items-center gap-4 border-b p-4 last:border-b-0">
                            <Skeleton className="size-4 rounded-sm" />
                            <Skeleton className="size-8 rounded-md" />
                            <div className="flex-1 space-y-2">
                                <Skeleton className="h-4 w-32" />
                                <Skeleton className="h-3 w-16" />
                            </div>
                            <Skeleton className="h-5 w-9 rounded-full" />
                            <div className="flex items-center gap-2">
                                <Skeleton className="size-8" />
                                <Skeleton className="size-8" />
                                <Skeleton className="size-8" />
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
