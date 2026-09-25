import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

export default function DashboardSkeleton() {
    return (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-4" aria-busy="true">
            <div className="space-y-6 lg:col-span-3">
                <Card className="gap-0 overflow-hidden py-0">
                    <div className="border-b px-4 py-3 sm:px-5"><Skeleton className="h-5 w-32" /></div>
                    <div className="grid grid-cols-2 gap-px bg-border sm:grid-cols-4">
                        {[0, 1, 2, 3].map(i => (
                            <div key={i} className="space-y-2 bg-card px-4 py-4 sm:px-5">
                                <Skeleton className="h-3 w-24" />
                                <Skeleton className="h-8 w-16" />
                            </div>
                        ))}
                    </div>
                </Card>
                <Card>
                    <CardHeader><Skeleton className="h-5 w-40" /></CardHeader>
                    <CardContent><Skeleton className="h-64 w-full" /></CardContent>
                </Card>
                <Card>
                    <CardHeader><Skeleton className="h-5 w-40" /></CardHeader>
                    <CardContent><Skeleton className="h-48 w-full" /></CardContent>
                </Card>
            </div>
            <div className="space-y-6">
                <Card>
                    <CardHeader><Skeleton className="h-5 w-28" /></CardHeader>
                    <CardContent className="space-y-3">
                        {[0, 1, 2, 3].map(i => <Skeleton key={i} className="h-4 w-full" />)}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
