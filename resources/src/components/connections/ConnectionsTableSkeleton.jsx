import { Skeleton } from "@/components/ui/skeleton";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { TABLE_HEADER_CLASS } from '@/lib/table';

// Mirrors the loaded table wrapper in pages/Connections.jsx so the layout does not shift.
export default function ConnectionsTableSkeleton() {
    return (
        <div className="overflow-hidden rounded-lg border bg-card">
            <Table>
                <TableHeader className={TABLE_HEADER_CLASS}>
                    <TableRow>
                        <TableHead className="w-[50px] px-6">
                            <Skeleton className="h-4 w-4" />
                        </TableHead>
                        <TableHead className="px-6"><Skeleton className="h-3 w-16" /></TableHead>
                        <TableHead className="px-6"><Skeleton className="h-3 w-20" /></TableHead>
                        <TableHead className="px-6"><Skeleton className="h-3 w-12" /></TableHead>
                        <TableHead className="px-6"><Skeleton className="h-3 w-16" /></TableHead>
                        <TableHead className="px-6"><Skeleton className="h-3 w-16" /></TableHead>
                        <TableHead className="px-6 text-right"><Skeleton className="h-3 w-20" /></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {[...Array(5)].map((_, i) => (
                        <TableRow key={i} className="hover:bg-muted/50">
                            <TableCell className="px-6">
                                <Skeleton className="h-4 w-4" />
                            </TableCell>
                            <TableCell className="px-6 py-4">
                                <div className="flex items-center gap-4">
                                    <Skeleton className="h-10 w-10 rounded-lg shrink-0" />
                                    <div className="space-y-2 min-w-0">
                                        <Skeleton className="h-3 w-32" />
                                        <Skeleton className="h-2 w-24" />
                                    </div>
                                </div>
                            </TableCell>
                            <TableCell className="px-6">
                                <div className="space-y-2">
                                    <Skeleton className="h-3 w-40" />
                                    <Skeleton className="h-2 w-28" />
                                </div>
                            </TableCell>
                            <TableCell className="px-6">
                                <Skeleton className="h-6 w-12 rounded-full" />
                            </TableCell>
                            <TableCell className="px-6">
                                <Skeleton className="h-6 w-20 rounded-full" />
                            </TableCell>
                            <TableCell className="px-6">
                                <div className="flex items-center gap-2">
                                    <Skeleton className="h-2 w-2 rounded-full" />
                                    <Skeleton className="h-3 w-24" />
                                </div>
                            </TableCell>
                            <TableCell className="px-6 text-right">
                                <div className="flex items-center justify-end gap-1">
                                    <Skeleton className="h-8 w-8" />
                                    <Skeleton className="h-8 w-8" />
                                    <Skeleton className="h-8 w-8" />
                                </div>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
