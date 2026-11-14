import * as React from "react";
import { cn } from "@/lib/utils";

export function Separator({ className, orientation = "horizontal", decorative = true, ...props }: React.HTMLAttributes<HTMLDivElement> & { orientation?: "horizontal" | "vertical"; decorative?: boolean }) {
  return <div role={decorative ? "none" : "separator"} aria-orientation={decorative ? undefined : orientation} data-orientation={orientation} className={cn("ui-separator", `ui-separator-${orientation}`, className)} {...props} />;
}
